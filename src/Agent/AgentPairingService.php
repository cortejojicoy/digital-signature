<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kukux\DigitalSignature\Enums\DeviceType;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\AgentToken;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Pairing a desktop agent, device-code style, with two confirmations:
 *
 *   start()    web    — a code for the user to type into the agent
 *   lookup()   agent  — the code proves the agent is beside the user
 *   claim()    agent  — the agent's keys, with a proof from the identity key
 *   confirm()  web    — the logged-in user approves *that* machine
 *   poll()     agent  — exchanges the confirmation for a token, once
 *
 * So a stolen session cannot pair a machine without the code reaching it,
 * and a code typed into the wrong machine still needs the owner's click.
 * Wire contract: digital-signature-agent/docs/protocol.md "Pairing".
 *
 * One signature per computer for this app (multi-app-pairing-plan.md §7.2):
 * a computer is its salted hardware id. While one account's agent device is
 * active on it, another account's claim is refused; the same account pairing
 * again updates that device in place ("rebind") rather than adding one.
 */
class AgentPairingService
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const PROTECTIONS = ['secure_enclave', 'tpm', 'software'];

    public function __construct(private readonly DeviceRegistry $registry) {}

    // -------------------------------------------------------------------------
    // Web
    // -------------------------------------------------------------------------

    /**
     * @return array{pairing: AgentPairing, user_code: string, link: string}
     */
    public function start(int $userId): array
    {
        // One live pairing per user: a fresh code retires the last one.
        AgentPairing::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'awaiting_confirmation'])
            ->update(['status' => 'expired']);

        $code = '';
        foreach (str_split(random_bytes(8)) as $byte) {
            $code .= self::ALPHABET[ord($byte) % strlen(self::ALPHABET)];
        }
        $userCode = substr($code, 0, 4).'-'.substr($code, 4);

        $pairing = AgentPairing::create([
            'uuid'           => (string) Str::uuid(),
            'user_id'        => $userId,
            'user_code_hash' => $this->hashCode($userCode),
            'nonce'          => AgentServer::token(),
            'status'         => 'pending',
            'expires_at'     => now()->addSeconds((int) config('signature.devices.agent.pairing_ttl', 600)),
        ]);

        $link = sprintf(
            '%s://pair?o=%s&c=%s',
            AgentServer::scheme(),
            rawurlencode(AgentServer::origin()),
            $userCode,
        );

        return ['pairing' => $pairing, 'user_code' => $userCode, 'link' => $link];
    }

    /**
     * Create the device from the agent's claim, or, when this user's agent
     * already holds this computer, give that device the new keys.
     *
     * @param  string|null  $deviceType  the owner's correction of the detected type (Enums\DeviceType value).
     *
     * @throws InvalidArgumentException  when the pairing is not awaiting this user, or another
     *                                   account holds this computer, or its type is blocked.
     * @throws UnregisteredDeviceException  at max_per_user.
     */
    public function confirm(AgentPairing $pairing, int $userId, ?string $deviceType = null): SigningDevice
    {
        try {
            return DB::transaction(fn () => $this->confirmLocked($pairing, $userId, $deviceType));
        } catch (UniqueConstraintViolationException $e) {
            // Another pairing committed first: for this computer, or for this account.
            throw new InvalidArgumentException(str_contains($e->getMessage(), 'active_agent_user') ? self::ACCOUNT_TAKEN : self::MACHINE_TAKEN);
        }
    }

    private const MACHINE_TAKEN = 'This computer is already paired with another account on this app. '
        .'They can remove it from their signing devices, or an admin can release it.';

    private const ACCOUNT_TAKEN = 'Your account is already paired with another computer on this app. '
        .'Remove it from your signing devices, then pair this one.';

    private function confirmLocked(AgentPairing $pairing, int $userId, ?string $deviceType): SigningDevice
    {
        $pairing = AgentPairing::query()->lockForUpdate()->findOrFail($pairing->id);

        if ((int) $pairing->user_id !== $userId || $pairing->status !== 'awaiting_confirmation' || $pairing->isExpired()) {
            throw new InvalidArgumentException('This pairing is no longer waiting for confirmation.');
        }

        $claim = $pairing->claim;
        $identity = DeviceProofVerifier::parsePublicKey($claim['identity_public_key']);
        $session = DeviceProofVerifier::parsePublicKey($claim['session_public_key']);
        $device = $claim['device'];

        $detected = DeviceType::fromAgent($device['device_type'] ?? null, $device['form_factor'] ?? null);
        $virtual = (bool) ($device['virtual'] ?? false);

        // The policy may have changed since the claim.
        if (self::blocks($detected, $virtual)) {
            throw new InvalidArgumentException(self::blockedMessage($detected, $virtual));
        }

        // Checked again under the lock: another pairing may have taken this
        // computer since the claim.
        $hardware = ($device['hardware_id_hash'] ?? '') ?: null;
        $holders = $this->activeAgentsOn($hardware, lock: true);

        if ($holders->contains(fn (SigningDevice $d) => (int) $d->user_id !== $userId)) {
            throw new InvalidArgumentException(self::MACHINE_TAKEN);
        }

        // The device the agent proved it holds at claim, if it's still active.
        $proven = isset($claim['proven_device_id'])
            ? SigningDevice::query()->lockForUpdate()->whereKey($claim['proven_device_id'])
                ->where('user_id', $userId)->where('kind', 'agent')->active()->first()
            : null;

        // And that this account hasn't paired another computer since.
        if ($blocker = $this->blockingComputer($this->activeAgentsFor($userId, lock: true), $hardware, $proven)) {
            throw new InvalidArgumentException(self::accountTakenMessage($blocker));
        }

        // The proven device, else this computer's newest. Other duplicates on
        // this computer (from before this rule, kept on upgrade) are retired
        // now that it has one pairing again.
        $existing = $proven ?? $holders->first();
        $holders->reject(fn (SigningDevice $d) => $d->is($existing))->each(fn (SigningDevice $old) => $this->registry->revoke($old));

        $shown = $this->chooseType($detected, $deviceType);

        $attributes = [
            'public_key'           => $identity['pem'],
            'key_fingerprint'      => $identity['fingerprint'],
            'algorithm'            => $identity['algorithm'],
            'kind'                 => 'agent',
            'protection'           => $claim['protection'],
            'user_presence'        => (bool) $claim['user_presence'],
            // Phase 5: verify $claim['attestation'] against the Microsoft
            // TPM roots. Until then nothing is marked attested.
            'attested'             => false,
            'device_type'          => $shown->value,
            'detected_device_type' => $detected->value,
            'chassis_type'         => $device['chassis_type'] ?? null,
            'virtual'              => $virtual,
            'form_factor'          => $device['form_factor'],
            'platform'             => $device['platform'],
            'model'                => $device['model'],
            'user_agent'           => sprintf('Kukux Sign Agent %s (%s %s)', $claim['agent_version'], $device['platform'], $device['os_version']),
            'hardware_id_hash'     => $hardware,
            'agent_version'        => $claim['agent_version'],
            'session_public_key'   => $session['pem'],
            'status'               => 'active',
            'approved_at'          => now(),
        ];

        if ($existing !== null) {
            // Rebind: same row, uuid and label, so signature history and
            // "Used on" stay intact. The old keys and token stop working.
            $existing->update($attributes + ['rebound_at' => now(), 'revoked_at' => null]);

            AgentToken::query()
                ->where('device_id', $existing->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $row = $existing;
            $event = SignatureAudit::AGENT_REBOUND;
        } else {
            $this->registry->assertCapacity($userId);

            $row = SigningDevice::create($attributes + [
                'uuid'          => (string) Str::uuid(),
                'user_id'       => $userId,
                'label'         => $device['label'] ?: ($device['model'] ?: 'Computer'),
                'registered_ip' => $claim['ip'] ?? null,
            ]);
            $event = SignatureAudit::AGENT_PAIRED;
        }

        $pairing->update([
            'status'             => 'confirmed',
            'device_id'          => $row->id,
            'replaces_device_id' => $existing?->id,
        ]);

        SignatureAudit::record($event, [
            'subject_user_id' => $userId,
            'device_id'       => $row->id,
            'context'         => ['pairing' => $pairing->uuid, 'protection' => $row->protection, 'device_type' => $row->device_type],
        ]);

        // New keys either way, so the owner hears about it either way.
        $this->registry->announce($row);

        return $row;
    }

    /**
     * An admin frees a computer from the account it's paired with, say when
     * the owner left, or unpaired while the server was unreachable.
     */
    public function release(SigningDevice $device, ?int $actorId = null): void
    {
        if ($device->kind !== 'agent') {
            throw new InvalidArgumentException('Only desktop agent devices hold a computer.');
        }

        $wasActive = $device->isActive();
        $this->registry->revoke($device);

        if ($wasActive) {
            SignatureAudit::record(SignatureAudit::AGENT_RELEASED, [
                'subject_user_id' => $device->user_id,
                'actor_user_id'   => $actorId,
                'device_id'       => $device->id,
                'context'         => ['label' => $device->displayName()],
            ]);
        }
    }

    public function reject(AgentPairing $pairing, int $userId): void
    {
        if ((int) $pairing->user_id === $userId && in_array($pairing->status, ['pending', 'awaiting_confirmation'], true)) {
            $pairing->update(['status' => 'rejected']);
        }
    }

    /**
     * What the confirm prompt shows: "Pair Juan's MacBook Pro (macOS 15.1,
     * Secure Enclave · Touch ID)?"
     *
     * @return array<string, mixed>|null
     */
    public function describeClaim(AgentPairing $pairing): ?array
    {
        $claim = $pairing->claim;

        if (! is_array($claim)) {
            return null;
        }

        $device = $claim['device'];
        $detected = DeviceType::fromAgent($device['device_type'] ?? null, $device['form_factor'] ?? null);
        $hardware = ($device['hardware_id_hash'] ?? '') ?: null;

        // This user's device on this computer, which confirming updates: the
        // one the agent proved it holds, else the one with this hash.
        $proven = isset($claim['proven_device_id'])
            ? $this->activeAgentsFor((int) $pairing->user_id)->firstWhere('id', $claim['proven_device_id'])
            : null;
        $replaces = $proven ?? $this->activeAgentsOn($hardware)
            ->first(fn (SigningDevice $d) => (int) $d->user_id === (int) $pairing->user_id);

        // This user's other computer, which keeps them from confirming this one.
        $blocker = $this->blockingComputer($this->activeAgentsFor((int) $pairing->user_id), $hardware, $proven);

        // Where else the account can sign, so the owner sees it before adding a computer.
        $others = SigningDevice::query()
            ->where('user_id', $pairing->user_id)
            ->active()
            ->when($replaces, fn ($q) => $q->whereKeyNot($replaces->id))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SigningDevice $d) => $d->displayName().' ('.$d->deviceType()->label().')')
            ->all();

        return [
            'label'           => $device['label'] ?: $device['model'],
            'model'           => $device['model'],
            'platform'        => trim($device['platform'].' '.$device['os_version']),
            'protection'      => match ($claim['protection']) {
                'secure_enclave' => 'Secure Enclave',
                'tpm'            => 'TPM',
                default          => 'Software key',
            },
            'presence'        => (bool) $claim['user_presence'],
            'version'         => $claim['agent_version'],
            'replaces'        => $replaces?->displayName(),
            'blocked_by'      => $blocker?->displayName(),
            'device_type'     => $detected->value,
            // The owner may correct the type, but not away from a VM (policy goes by detection).
            'type_locked'     => $detected === DeviceType::VirtualMachine,
            'identified'      => ! empty($device['hardware_id_hash']),
            'other_devices'   => $others,
        ];
    }

    // -------------------------------------------------------------------------
    // Agent
    // -------------------------------------------------------------------------

    /**
     * @throws AgentApiException
     */
    public function lookup(string $userCode): array
    {
        $pairing = AgentPairing::query()
            ->where('user_code_hash', $this->hashCode($userCode))
            ->where('status', 'pending')
            ->first();

        if ($pairing === null || $pairing->isExpired()) {
            throw new AgentApiException(404, 'invalid_code', 'That code is invalid or has expired.');
        }

        $user = $pairing->user;
        $computer = $this->activeAgentsFor((int) $pairing->user_id)->first();

        return [
            'pairing'          => $pairing->uuid,
            'nonce'            => $pairing->nonce,
            'user_id'          => (string) $pairing->user_id,
            'user_name'        => (string) ($user->name ?? $user->email ?? ''),
            'server'           => [
                'id'     => AgentServer::id(),
                'name'   => AgentServer::name(),
                'origin' => AgentServer::origin(),
                'salt'   => AgentServer::salt(),
            ],
            'require_presence'     => (bool) config('signature.devices.agent.require_presence', true),
            'blocked_device_types' => self::blockedTypes(),
            // The account's paired computer, so the agent can refuse a second
            // one before any key or Touch ID prompt. The hash only says
            // whether it's this computer; only this account's code gets it.
            'agent_device'         => $computer === null ? null : [
                'uuid'             => $computer->uuid,
                'label'            => $computer->displayName(),
                'device_type'      => $computer->deviceType()->value,
                'hardware_id_hash' => $computer->hardware_id_hash ?: null,
            ],
            'devices_url'          => config('signature.devices.agent.devices_url') ?: AgentServer::origin(),
            'expires_at'           => $pairing->expires_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AgentApiException
     */
    public function claim(string $uuid, array $input, ?string $ip): array
    {
        return DB::transaction(function () use ($uuid, $input, $ip) {
            $pairing = AgentPairing::query()->where('uuid', $uuid)->lockForUpdate()->first();

            if ($pairing === null || $pairing->status !== 'pending' || $pairing->isExpired()) {
                throw new AgentApiException(404, 'invalid_pairing', 'Pairing not found, or it has expired.');
            }

            if (! hash_equals($pairing->user_code_hash, $this->hashCode((string) ($input['user_code'] ?? '')))) {
                throw new AgentApiException(403, 'invalid_code', 'Wrong pairing code.');
            }

            $algorithm = $input['algorithm'] ?? null;

            if (! in_array($algorithm, ['ES256', 'RS256'], true)) {
                throw new AgentApiException(422, 'invalid_algorithm', 'Unsupported key algorithm.');
            }

            try {
                $identity = DeviceProofVerifier::parsePublicKey((string) ($input['identity_public_key'] ?? ''));
                $session = DeviceProofVerifier::parsePublicKey((string) ($input['session_public_key'] ?? ''));
            } catch (InvalidArgumentException $e) {
                throw new AgentApiException(422, 'invalid_key', $e->getMessage());
            }

            if ($identity['algorithm'] !== $algorithm || $session['algorithm'] !== 'ES256') {
                throw new AgentApiException(422, 'invalid_key', 'The keys do not match the declared algorithms.');
            }

            // Both keys under one proof: the session key is only trusted
            // because the identity key vouched for it.
            $bound = hash('sha256', base64_decode($input['identity_public_key']).base64_decode($input['session_public_key']));
            $message = DeviceProofVerifier::message('register_agent', $pairing->nonce, $pairing->user_id, $bound);
            $proof = base64_decode((string) ($input['proof'] ?? ''), true);

            if ($proof === false || ! DeviceProofVerifier::verify($identity['pem'], $algorithm, $message, $proof, 'der')) {
                throw new AgentApiException(422, 'invalid_proof', 'The registration proof did not verify.');
            }

            $presence = (bool) ($input['user_presence'] ?? false);

            if (config('signature.devices.agent.require_presence', true) && ! $presence) {
                throw new AgentApiException(422, 'presence_required', 'This server requires Touch ID or Windows Hello on the signing key.');
            }

            if (SigningDevice::query()->where('key_fingerprint', $identity['fingerprint'])->exists()) {
                throw new AgentApiException(409, 'key_already_registered', 'This key is already registered.');
            }

            $device = is_array($input['device'] ?? null) ? $input['device'] : [];
            $text = fn (string $key, int $max): string => Str::limit(trim(strip_tags((string) ($device[$key] ?? ''))), $max, '');
            $protection = in_array($input['protection'] ?? null, self::PROTECTIONS, true) ? $input['protection'] : 'software';
            $hardware = (string) ($device['hardware_id_hash'] ?? '');
            // A firmware placeholder is shared by many boards: no id at all.
            $hardware = preg_match('/^[0-9a-f]{64}$/', $hardware) && ! in_array($hardware, self::placeholderHashes(), true) ? $hardware : '';
            $formFactor = in_array($device['form_factor'] ?? null, ['laptop', 'desktop'], true) ? $device['form_factor'] : 'unknown';
            $detected = DeviceType::fromAgent($device['device_type'] ?? null, $formFactor);
            $virtual = ($device['virtual'] ?? false) === true;
            $chassis = $device['chassis_type'] ?? null;
            $chassis = is_int($chassis) && $chassis >= 1 && $chassis <= 127 ? $chassis : null;

            if (self::blocks($detected, $virtual)) {
                throw new AgentApiException(422, 'device_type_not_allowed', self::blockedMessage($detected, $virtual));
            }

            // One signature per computer for this app. The message never
            // says whose: that would let anyone probe who owns a computer.
            $holders = $this->activeAgentsOn($hardware ?: null);

            if ($holders->contains(fn (SigningDevice $d) => (int) $d->user_id !== (int) $pairing->user_id)) {
                throw new AgentApiException(409, 'machine_already_paired', 'This computer is already paired with another account on this app.');
            }

            // Re-pairing: the old pairing's session key signed the new keys,
            // so this is that device's computer whatever its hash says.
            $proven = $this->provenDevice($pairing, $input['replaces'] ?? null, $bound);

            // One computer per account for this app. Names only the account's
            // own computer, to the holder of the account's own code.
            if ($blocker = $this->blockingComputer($this->activeAgentsFor((int) $pairing->user_id), $hardware ?: null, $proven)) {
                throw new AgentApiException(409, 'account_already_paired', self::accountTakenMessage($blocker), [
                    'device' => ['label' => $blocker->displayName(), 'device_type' => $blocker->deviceType()->value],
                ]);
            }

            $existing = $proven ?? $holders->first();
            $pollSecret = AgentServer::token();

            $pairing->update([
                'status'             => 'awaiting_confirmation',
                'poll_secret_hash'   => hash('sha256', $pollSecret),
                'replaces_device_id' => $existing?->id,
                'claim'              => [
                    'identity_public_key' => (string) $input['identity_public_key'],
                    'session_public_key'  => (string) $input['session_public_key'],
                    'algorithm'           => $algorithm,
                    'protection'          => $protection,
                    'user_presence'       => $presence,
                    'attestation'         => is_array($input['attestation'] ?? null) ? $input['attestation'] : null,
                    'agent_version'       => Str::limit((string) ($input['agent_version'] ?? ''), 32, ''),
                    'device'              => [
                        'platform'         => match ($device['platform'] ?? null) {
                            'macos'   => 'macOS',
                            'windows' => 'Windows',
                            default   => $text('platform', 32),
                        },
                        'os_version'       => $text('os_version', 32),
                        'model'            => $text('model', 120),
                        'model_identifier' => $text('model_identifier', 64),
                        'form_factor'      => $formFactor,
                        'label'            => $text('label', 120),
                        'hardware_id_hash' => $hardware,
                        'device_type'      => $detected->value,
                        'chassis_type'     => $chassis,
                        'virtual'          => $virtual,
                    ],
                    'ip'                  => $ip,
                    'proven_device_id'    => $proven?->id,
                ],
            ]);

            return [
                'status'          => 'awaiting_confirmation',
                'poll_secret'     => $pollSecret,
                'existing_device' => $existing === null ? null : [
                    'uuid'        => $existing->uuid,
                    'label'       => $existing->displayName(),
                    'device_type' => $existing->deviceType()->value,
                ],
            ];
        });
    }

    /**
     * @throws AgentApiException
     */
    public function poll(string $uuid, string $pollSecret): array
    {
        return DB::transaction(function () use ($uuid, $pollSecret) {
            $pairing = AgentPairing::query()->where('uuid', $uuid)->lockForUpdate()->first();

            if ($pairing === null || $pairing->poll_secret_hash === null
                || ! hash_equals($pairing->poll_secret_hash, hash('sha256', $pollSecret))) {
                throw new AgentApiException(404, 'invalid_pairing', 'Pairing not found.');
            }

            if ($pairing->status === 'confirmed') {
                if ($pairing->token_issued_at !== null) {
                    throw new AgentApiException(409, 'token_already_issued', 'The token for this pairing was already issued.');
                }

                $token = AgentServer::token();

                AgentToken::create(['device_id' => $pairing->device_id, 'token_hash' => hash('sha256', $token)]);
                $pairing->update(['token_issued_at' => now()]);

                $device = $pairing->device;

                return [
                    'status'  => 'confirmed',
                    'device'  => [
                        'uuid'        => $device->uuid,
                        'label'       => $device->displayName(),
                        'device_type' => $device->deviceType()->value,
                    ],
                    'token'   => $token,
                    'rebound' => $pairing->replaces_device_id !== null,
                ];
            }

            if (in_array($pairing->status, ['pending', 'awaiting_confirmation'], true) && $pairing->isExpired()) {
                $pairing->update(['status' => 'expired']);
            }

            return ['status' => $pairing->status];
        });
    }

    /**
     * Active agent devices on this computer for this app, newest first.
     * Normally at most one; older installs may hold duplicates.
     *
     * @return Collection<int, SigningDevice>
     */
    private function activeAgentsOn(?string $hardwareIdHash, bool $lock = false): Collection
    {
        if ($hardwareIdHash === null || $hardwareIdHash === '') {
            return new Collection;
        }

        return SigningDevice::query()
            ->where('kind', 'agent')
            ->where('hardware_id_hash', $hardwareIdHash)
            ->active()
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * This user's active agent devices for this app, newest first. Normally
     * at most one; older installs may hold several.
     *
     * @return Collection<int, SigningDevice>
     */
    private function activeAgentsFor(int $userId, bool $lock = false): Collection
    {
        return SigningDevice::query()
            ->where('kind', 'agent')
            ->where('user_id', $userId)
            ->active()
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Of the account's agent devices, the first that isn't provably this
     * computer: it keeps the account from pairing here. Proof is the rebind
     * proof ($proven), or a matching hardware id. Without a hardware id on
     * either side there's no proof, so that counts as another computer.
     *
     * @param  Collection<int, SigningDevice>  $mine
     */
    private function blockingComputer(Collection $mine, ?string $hardwareIdHash, ?SigningDevice $proven = null): ?SigningDevice
    {
        return $mine->first(fn (SigningDevice $d) => ! $d->is($proven)
            && ($hardwareIdHash === null || $d->hardware_id_hash !== $hardwareIdHash));
    }

    /**
     * The account's active agent device whose session key signed the new
     * keys (`rebind_agent`). That key never leaves the computer's Secure
     * Enclave / TPM, so this proves the claim comes from that device's
     * computer even without a hardware id (one-computer-per-account-plan.md
     * §13). Null without a proof, or when that device is gone.
     *
     * @throws AgentApiException when a proof is sent and doesn't verify.
     */
    private function provenDevice(AgentPairing $pairing, mixed $replaces, string $bound): ?SigningDevice
    {
        if (! is_array($replaces) || ! is_string($replaces['device_uuid'] ?? null)) {
            return null;
        }

        $device = SigningDevice::query()
            ->where('uuid', $replaces['device_uuid'])
            ->where('user_id', $pairing->user_id)
            ->where('kind', 'agent')
            ->active()
            ->first();

        if ($device === null || ! $device->session_public_key) {
            return null;
        }

        $proof = base64_decode((string) ($replaces['proof'] ?? ''), true);
        $message = DeviceProofVerifier::message('rebind_agent', $pairing->nonce, $pairing->user_id, $bound);

        if ($proof === false || ! DeviceProofVerifier::verify($device->session_public_key, 'ES256', $message, $proof, 'der')) {
            throw new AgentApiException(422, 'invalid_proof', 'The re-pairing proof did not verify.');
        }

        return $device;
    }

    /**
     * Firmware placeholder UUIDs (tests/Fixtures/placeholder-uuids.json, and
     * any UUID of one repeated hex digit), hashed the way the agent hashes a
     * hardware UUID. The agent already sends no id for these; this catches
     * older agents (one-computer-per-account-plan.md §14).
     */
    public const PLACEHOLDER_UUIDS = [
        '03000200-0400-0500-0006-000700080009',
        '00020003-0004-0005-0006-000700080009',
        '12345678-1234-5678-90AB-CDDEEFAABBCC',
        '01234567-8910-1112-1314-151617181920',
        '11111111-2222-3333-4444-555555555555',
    ];

    /** @return list<string> */
    private static function placeholderHashes(): array
    {
        $repeated = array_map(
            fn (string $d) => implode('-', [str_repeat($d, 8), str_repeat($d, 4), str_repeat($d, 4), str_repeat($d, 4), str_repeat($d, 12)]),
            str_split('0123456789ABCDEF'),
        );
        $salt = AgentServer::salt();

        return array_map(fn (string $uuid) => hash('sha256', $salt.$uuid), [...self::PLACEHOLDER_UUIDS, ...$repeated]);
    }

    private static function accountTakenMessage(SigningDevice $computer): string
    {
        return "Your account is already paired with another computer on this app: {$computer->displayName()}. "
            .'One account can be paired with only one computer. '
            ."Remove {$computer->displayName()} from your signing devices on the web, then pair this computer.";
    }

    /** The owner's correction wins, except over a detected VM. */
    private function chooseType(DeviceType $detected, ?string $override): DeviceType
    {
        if ($detected === DeviceType::VirtualMachine || $override === null) {
            return $detected;
        }

        return DeviceType::tryFrom($override) ?? $detected;
    }

    /**
     * signature.devices.agent.blocked_device_types, as catalogue values.
     *
     * @return list<string>
     */
    public static function blockedTypes(): array
    {
        $configured = config('signature.devices.agent.blocked_device_types', ['virtual_machine']);

        return array_values(array_filter(
            is_array($configured) ? $configured : explode(',', (string) $configured),
            fn ($type) => is_string($type) && DeviceType::tryFrom(trim($type)) !== null,
        ));
    }

    /** A VM is blocked when either the type or the firmware flag says so. */
    public static function blocks(DeviceType $detected, bool $virtual): bool
    {
        $blocked = self::blockedTypes();

        return in_array($detected->value, $blocked, true)
            || ($virtual && in_array(DeviceType::VirtualMachine->value, $blocked, true));
    }

    private static function blockedMessage(DeviceType $detected, bool $virtual): string
    {
        return $virtual || $detected === DeviceType::VirtualMachine
            ? "Pairing from a virtual machine isn't allowed on this app. Install the agent on the computer itself."
            : "This app doesn't allow pairing from this kind of device ({$detected->label()}).";
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]/', '', $code)));
    }
}
