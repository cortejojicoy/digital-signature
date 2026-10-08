<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\Transfer;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Services\CertificateService;

/**
 * Moving a person's signature to a new computer (plan 1.6, R11).
 *
 * One computer per person: when someone identifies as a person already
 * linked to another account, the new account doesn't get the link. Instead:
 *
 *   request()  the old computer gets an agent job, purpose `transfer`
 *              ("Move Juan's signature to a new computer (MacBook Air)?");
 *              without an old computer it waits in the admins' Pending claims
 *   apply()    the old computer approved (HandleIdentityAgentJobs), or an
 *              admin did after checking the person's ID
 *   reject()   the old computer refused, or an admin did
 *
 * Applying moves the personnel link and the person's own signature rows to
 * the new account, releases the old computer, revokes the old account's
 * certificate (the next signature issues a new one) and retires the old
 * account, kept for the audit trail. The OIDC `sub` is the personnel key, so
 * apps see the same person throughout.
 */
class IdentityTransfer
{
    public const JOB_TITLE = 'Move your signature to a new computer';

    public function __construct(
        private readonly IdentityService $identities,
        private readonly AgentJobService $jobs,
        private readonly HubApiBridge $api,
    ) {}

    public function request(Identity $new, Identity $old, Personnel $person): Transfer
    {
        $existing = Transfer::query()
            ->where('to_user_id', $new->user_id)
            ->where('personnel_key', $person->key)
            ->where('status', 'pending')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($new, $old, $person) {
            $transfer = Transfer::create([
                'uuid'          => (string) Str::uuid(),
                'personnel_key' => $person->key,
                'from_user_id'  => $old->user_id,
                'to_user_id'    => $new->user_id,
                'status'        => 'pending',
            ]);

            // The old computer approves, when there still is one. Otherwise
            // the request simply waits for an admin.
            if ($this->identities->computer((int) $old->user_id) !== null) {
                [$job] = $this->jobs->create((int) $old->user_id, 'transfer', self::JOB_TITLE, self::payloadHash($transfer), [
                    'ttl'  => (int) config('signature.hub.transfer_ttl', 86400),
                    'meta' => ['transfer' => [
                        'name'   => $person->name,
                        'device' => $this->identities->computer((int) $new->user_id)?->displayName() ?? 'a new computer',
                    ]],
                ]);

                $transfer->update(['agent_job_id' => $job->id]);
            }

            SignatureAudit::record(SignatureAudit::TRANSFER_REQUESTED, [
                'subject_user_id' => $new->user_id,
                'personnel_key'   => $person->key,
                'context'         => [
                    'transfer'      => $transfer->uuid,
                    'from_user_id'  => (int) $old->user_id,
                    'old_computer'  => $transfer->agent_job_id !== null,
                ],
            ]);

            return $transfer;
        });
    }

    /**
     * A fresh link for the old computer's pending approval, shown on that
     * account's Profile ("Approve on this computer"). Only the token's hash
     * is kept, so asking again issues a new one and the old link stops.
     */
    public function approvalLink(Transfer $transfer, int $viewerId): ?string
    {
        $job = $transfer->agentJob;

        if ($transfer->status !== 'pending' || $job === null || (int) $job->user_id !== $viewerId
            || $job->status !== 'pending' || $job->isExpired()) {
            return null;
        }

        $token = AgentServer::token();
        $job->update(['link_token_hash' => hash('sha256', $token)]);

        return $this->jobs->link($job, $token);
    }

    /**
     * Approve the move. $actorId is the old account (its computer approved)
     * or the admin who did.
     */
    public function apply(Transfer $transfer, ?int $actorId = null, bool $byAdmin = false): Transfer
    {
        $applied = DB::transaction(function () use ($transfer, $actorId, $byAdmin) {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== 'pending') {
                return null;
            }

            $from = (int) $transfer->from_user_id;
            $to = (int) $transfer->to_user_id;
            $old = Identity::forUser($from);
            $new = $this->identities->ensure($to);

            if (! $new->isUsable()) {
                $transfer->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now()]);

                return null;
            }

            // Approved by the person's own old computer: the claim carries its
            // verification over. Approved by an admin: they checked the ID.
            $status = $byAdmin || $old?->isVerified() || ! config('signature.hub.require_verification', true)
                ? Identity::VERIFIED
                : Identity::PENDING;

            // 1. The old account stops standing for the person (and frees its email).
            $this->identities->retire($from, 'transferred', $actorId);

            // 2. The personnel link moves.
            $new->update([
                'personnel_key' => $transfer->personnel_key,
                'status'        => $status,
                'claimed_at'    => now(),
                'verified_by'   => $status === Identity::VERIFIED ? ($byAdmin ? $actorId : $old?->verified_by) : null,
                'verified_at'   => $status === Identity::VERIFIED ? now() : null,
            ]);

            if (($person = $this->identities->directory()->find($transfer->personnel_key))
                && ($user = app(HubAccounts::class)->find($to))) {
                app(HubAccounts::class)->applyClaims($user, $this->identities->directory()->toClaims($person));
            }

            // 3. The person's own signature (not signed documents, which keep
            //    their history on the old account) moves with them.
            $moved = Signature::query()->where('user_id', $from)->primary()->update(['user_id' => $to]);

            // 4. The old computer is released; its certificate revoked, so the
            //    next signature issues one for the new account.
            $this->identities->releaseDevices($from, $actorId);

            UserCertificate::query()->where('user_id', $from)->whereNull('revoked_at')->get()
                ->each(fn (UserCertificate $cert) => app(CertificateService::class)->revoke($cert));

            $transfer->update(['status' => 'approved', 'decided_by' => $actorId, 'decided_at' => now()]);

            SignatureAudit::record(SignatureAudit::TRANSFER_APPROVED, [
                'subject_user_id' => $to,
                'actor_user_id'   => $actorId,
                'personnel_key'   => $transfer->personnel_key,
                'context'         => [
                    'transfer'      => $transfer->uuid,
                    'from_user_id'  => $from,
                    'by'            => $byAdmin ? 'admin' : 'old_computer',
                    'signatures'    => $moved,
                ],
            ]);

            return $transfer;
        });

        if ($applied === null) {
            return $transfer->refresh();
        }

        $this->api->signatureUpdated($applied->personnel_key);

        return $applied;
    }

    public function reject(Transfer $transfer, ?int $actorId = null, string $reason = 'declined'): Transfer
    {
        if ($transfer->status !== 'pending') {
            return $transfer;
        }

        $transfer->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now()]);

        // The old computer's job, if it never answered, is moot now.
        if ($transfer->agent_job_id !== null) {
            AgentJob::query()->whereKey($transfer->agent_job_id)->whereIn('status', ['pending', 'claimed'])
                ->update(['status' => 'rejected', 'reason' => 'declined', 'link_token_hash' => null]);
        }

        SignatureAudit::record(SignatureAudit::TRANSFER_REJECTED, [
            'subject_user_id' => $transfer->to_user_id,
            'actor_user_id'   => $actorId,
            'personnel_key'   => $transfer->personnel_key,
            'context'         => ['transfer' => $transfer->uuid, 'reason' => $reason],
        ]);

        return $transfer;
    }

    /** The pending move this account asked for, if any. */
    public function pendingFor(int $newUserId): ?Transfer
    {
        return Transfer::query()->where('to_user_id', $newUserId)->where('status', 'pending')->latest('id')->first();
    }

    /** What the old computer's identity key signs over: the transfer, by uuid. */
    public static function payloadHash(Transfer $transfer): string
    {
        return hash('sha256', 'transfer|'.$transfer->uuid);
    }

    public function forJob(AgentJob $job): ?Transfer
    {
        return Transfer::query()->where('agent_job_id', $job->id)->first();
    }
}
