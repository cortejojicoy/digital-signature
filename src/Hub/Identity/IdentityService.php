<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\HubBlock;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Models\Transfer;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Who a hub account is (plan 1.1–1.6). Every status change of an Identity
 * goes through here, so each one is audited the same way:
 *
 *   unidentified ──identify()──► pending_verification ──verify()──► verified
 *        │                            │                                │
 *        │                         reject() ──► rejected               │
 *        └──(person already linked)──► Hub\Identity\IdentityTransfer   │
 *                                                                      │
 *   retire() ──► retired   (the old account after a transfer)          │
 *   separate() ──► separated   (HR registry: the person left) ◄────────┘
 *
 * The hub API reads it through personnelKeyFor() / isVerified() /
 * userIdFor() (docs/hub/contracts.md §2.3). The host's Kafka consumer calls
 * separate() when HR reports a separation.
 */
class IdentityService
{
    public function __construct(
        private readonly AgentPairingService $pairings,
        private readonly HubAccounts $accounts,
        private readonly HubApiBridge $api,
    ) {}

    // ── Read side (hub API) ─────────────────────────────────────────────────

    /** The person an account stands for (the OIDC `sub`), once identified. */
    public function personnelKeyFor(int $userId): ?string
    {
        $identity = Identity::forUser($userId);

        return $identity?->isIdentified() ? $identity->personnel_key : null;
    }

    /** May sign, sign in to apps and hold a certificate (D9, D11). */
    public function isVerified(int $userId): bool
    {
        return (bool) Identity::forUser($userId)?->isVerified();
    }

    /** The person's current account (computer), if any. */
    public function userIdFor(string $personnelKey): ?int
    {
        $userId = Identity::current($personnelKey)?->user_id;

        return $userId === null ? null : (int) $userId;
    }

    public function directory(): PersonnelDirectory
    {
        return app(PersonnelDirectory::class);
    }

    /** The account's identity row, created `unidentified` when it has none. */
    public function ensure(int $userId): Identity
    {
        return Identity::query()->firstOrCreate(['user_id' => $userId], ['status' => Identity::UNIDENTIFIED]);
    }

    // ── Pair-first onboarding ───────────────────────────────────────────────

    /**
     * "Pair this computer": a provisional account bound to this browser.
     *
     * @return array{user: Model, identity: Identity}
     */
    public function startProvisional(string $browserHash): array
    {
        return DB::transaction(function () use ($browserHash) {
            $user = $this->accounts->createProvisional();

            $identity = Identity::create([
                'user_id'      => $user->getKey(),
                'status'       => Identity::UNIDENTIFIED,
                'session_hash' => $browserHash,
            ]);

            return ['user' => $user, 'identity' => $identity];
        });
    }

    /**
     * "Who are you?": counted against the account's quota (R9), whatever the
     * query, so short or empty probes cost the same as real ones.
     *
     * @return array<int, Personnel>
     *
     * @throws IdentityException
     */
    public function search(int $userId, string $query): array
    {
        $identity = $this->ensure($userId);
        $quota = (int) config('signature.hub.personnel.search_quota', 20);

        if ($identity->search_count >= $quota) {
            Log::warning('signature.hub: identification search quota reached.', ['user_id' => $userId]);

            throw new IdentityException('You have reached the search limit. Contact the signature help desk to finish identifying yourself.', 'quota');
        }

        $identity->increment('search_count');

        $query = trim($query);
        $isEmpNo = preg_match('/^[A-Za-z0-9-]{1,32}$/', $query) === 1;

        if (mb_strlen($query) < (int) config('signature.hub.personnel.min_search', 3) && ! $isEmpNo) {
            return [];
        }

        // Logged, so a probe across the registry shows up (R9 "detect").
        Log::info('signature.hub: identification search.', ['user_id' => $userId, 'length' => mb_strlen($query)]);

        return $this->directory()->search($query, (int) config('signature.hub.personnel.max_results', 10));
    }

    /**
     * The person picked themselves. Unclaimed: the account is linked to them.
     * Already linked to another account (a new computer): a transfer, which
     * the old computer or an admin has to approve.
     *
     * @throws IdentityException
     */
    public function identify(int $userId, string $personnelKey): Identity|Transfer
    {
        $identity = $this->ensure($userId);

        if ($identity->isIdentified()) {
            throw new IdentityException('This account has already been identified.', 'identified');
        }

        if (! $identity->isUsable()) {
            throw new IdentityException('This account can no longer be used. Pair this computer again.', 'unusable');
        }

        $person = $this->directory()->find($personnelKey);

        if ($person === null || ! $person->active) {
            throw new IdentityException('That person is not in the registry of active personnel.', 'unknown_person');
        }

        $current = Identity::current($personnelKey);

        if ($current !== null && (int) $current->user_id !== $userId) {
            return app(IdentityTransfer::class)->request($identity, $current, $person);
        }

        return $this->claim($userId, $personnelKey);
    }

    /**
     * Link an account to an unclaimed person: copy their name and email from
     * the registry, and wait for an admin (D9) unless verification is off.
     *
     * @throws IdentityException
     */
    public function claim(int $userId, string $personnelKey): Identity
    {
        $person = $this->directory()->find($personnelKey);

        if ($person === null || ! $person->active) {
            throw new IdentityException('That person is not in the registry of active personnel.', 'unknown_person');
        }

        $verified = ! config('signature.hub.require_verification', true);

        $identity = DB::transaction(function () use ($userId, $person, $verified) {
            $identity = Identity::query()->where('user_id', $userId)->lockForUpdate()->first() ?? $this->ensure($userId);

            // Checked again under the lock: two browsers may pick the same person.
            $current = Identity::current($person->key);

            if ($current !== null && (int) $current->user_id !== $userId) {
                throw new IdentityException("{$person->name} is already linked to another computer.", 'claimed');
            }

            if ($user = $this->accounts->find($userId)) {
                $this->accounts->applyClaims($user, $this->directory()->toClaims($person));
            }

            $identity->update([
                'personnel_key' => $person->key,
                'status'        => $verified ? Identity::VERIFIED : Identity::PENDING,
                'claimed_at'    => now(),
                'verified_at'   => $verified ? now() : null,
            ]);

            SignatureAudit::record(SignatureAudit::IDENTITY_CLAIMED, [
                'subject_user_id' => $userId,
                'personnel_key'   => $person->key,
                'context'         => array_filter([
                    'name'     => $person->name,
                    'emp_no'   => $person->empNo,
                    'unit'     => $person->unit,
                    'verified' => $verified ?: null,
                ]),
            ]);

            return $identity;
        });

        return $identity->refresh();
    }

    // ── Admin decisions ─────────────────────────────────────────────────────

    /**
     * An admin confirmed the person is who they picked (Pending claims), or
     * `signature:hub-admin` bootstrapped the first super_admin.
     *
     * @throws IdentityException
     */
    public function verify(int $userId, ?int $actorId = null, ?string $actorType = null): Identity
    {
        $identity = Identity::forUser($userId);

        if ($identity === null || ! $identity->isIdentified()) {
            throw new IdentityException('Only a claimed identity can be verified.', 'not_claimed');
        }

        if ($identity->isVerified()) {
            return $identity;
        }

        $identity->update([
            'status'      => Identity::VERIFIED,
            'verified_by' => $actorId,
            'verified_at' => now(),
        ]);

        SignatureAudit::record(SignatureAudit::IDENTITY_VERIFIED, array_filter([
            'subject_user_id' => $userId,
            'actor_user_id'   => $actorId,
            'actor_type'      => $actorType,
            'personnel_key'   => $identity->personnel_key,
        ], fn ($v) => $v !== null));

        return $identity;
    }

    /**
     * A false or mistaken claim (R8): the person is unlinked, the computer
     * released and refused for `hub.block_days`, and the account retired.
     */
    public function reject(int $userId, ?int $actorId = null, string $reason = 'rejected'): Identity
    {
        $identity = $this->ensure($userId);

        $blocked = DB::transaction(function () use ($identity, $userId, $actorId, $reason) {
            $hashes = $this->releaseDevices($userId, $actorId);

            foreach ($hashes as $hash) {
                HubBlock::create([
                    'hardware_id_hash' => $hash,
                    'reason'           => 'identity_rejected',
                    'until'            => now()->addDays((int) config('signature.hub.block_days', 30)),
                ]);
            }

            $identity->update(['status' => Identity::REJECTED, 'retired_reason' => $reason]);

            // Their move requests die with them.
            Transfer::query()->where('to_user_id', $userId)->where('status', 'pending')
                ->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now()]);

            if ($user = $this->accounts->find($userId)) {
                $this->accounts->vacate($user, 'rejected');
            }

            SignatureAudit::record(SignatureAudit::IDENTITY_REJECTED, [
                'subject_user_id' => $userId,
                'actor_user_id'   => $actorId,
                'personnel_key'   => $identity->personnel_key,
                'context'         => ['reason' => $reason, 'blocked_computers' => count($hashes)],
            ]);

            return $hashes;
        });

        Log::notice('signature.hub: identity rejected.', ['user_id' => $userId, 'blocked' => count($blocked)]);

        return $identity->refresh();
    }

    /**
     * HR says the person left (the host's Kafka consumer calls this): every
     * account of theirs stops, devices are released, the signature and
     * certificate revoked, and apps get `person.separated`.
     */
    public function separate(string $personnelKey, ?int $actorId = null): void
    {
        $identities = Identity::query()
            ->where('personnel_key', $personnelKey)
            ->whereIn('status', [Identity::PENDING, Identity::VERIFIED])
            ->get();

        foreach ($identities as $identity) {
            $userId = (int) $identity->user_id;

            DB::transaction(function () use ($identity, $userId, $actorId) {
                $this->releaseDevices($userId, $actorId);
                $identity->update(['status' => Identity::SEPARATED, 'retired_reason' => 'separated']);

                Transfer::query()->where('personnel_key', $identity->personnel_key)->where('status', 'pending')
                    ->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now()]);

                SignatureAudit::record(SignatureAudit::IDENTITY_SEPARATED, [
                    'subject_user_id' => $userId,
                    'actor_user_id'   => $actorId,
                    'personnel_key'   => $identity->personnel_key,
                ]);
            });

            // Signature, certificate and the apps' mirrors; audited there.
            $this->api->revokeSignature($userId, 'separated', $actorId);
        }

        $this->api->personSeparated($personnelKey);
    }

    /** An account that no longer stands for its person (after a transfer). */
    public function retire(int $userId, string $reason, ?int $actorId = null): Identity
    {
        $identity = $this->ensure($userId);
        $identity->update(['status' => Identity::RETIRED, 'retired_reason' => $reason]);

        if ($user = $this->accounts->find($userId)) {
            $this->accounts->vacate($user, 'retired');
        }

        return $identity;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The account's paired computer, if it still has one. */
    public function computer(int $userId): ?SigningDevice
    {
        return SigningDevice::query()
            ->where('user_id', $userId)
            ->where('kind', 'agent')
            ->active()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Release every agent computer (and revoke every other device) of an
     * account. Returns the released computers' hardware id hashes.
     *
     * @return array<int, string>
     */
    public function releaseDevices(int $userId, ?int $actorId = null): array
    {
        $hashes = [];

        $devices = SigningDevice::query()->where('user_id', $userId)->where('status', '!=', 'revoked')->get();

        foreach ($devices as $device) {
            if ($device->kind === 'agent') {
                if ($device->hardware_id_hash) {
                    $hashes[] = $device->hardware_id_hash;
                }

                $this->pairings->release($device, $actorId);
            } else {
                app(DeviceRegistry::class)->revoke($device);
            }
        }

        return array_values(array_unique($hashes));
    }
}
