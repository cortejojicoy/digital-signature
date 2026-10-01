<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
     * Create the device from the agent's claim.
     *
     * @throws InvalidArgumentException  when the pairing is not awaiting this user.
     * @throws UnregisteredDeviceException  at max_per_user.
     */
    public function confirm(AgentPairing $pairing, int $userId): SigningDevice
    {
        return DB::transaction(function () use ($pairing, $userId) {
            $pairing = AgentPairing::query()->lockForUpdate()->findOrFail($pairing->id);

            if ((int) $pairing->user_id !== $userId || $pairing->status !== 'awaiting_confirmation' || $pairing->isExpired()) {
                throw new InvalidArgumentException('This pairing is no longer waiting for confirmation.');
            }

            $this->registry->assertCapacity($userId);

            $claim = $pairing->claim;
            $identity = DeviceProofVerifier::parsePublicKey($claim['identity_public_key']);
            $session = DeviceProofVerifier::parsePublicKey($claim['session_public_key']);
            $device = $claim['device'];

            // The same machine paired again (say, after a reinstall): its old
            // keys are gone, so its old device row should be too.
            if (! empty($device['hardware_id_hash'])) {
                SigningDevice::query()
                    ->where('user_id', $userId)
                    ->where('kind', 'agent')
                    ->where('hardware_id_hash', $device['hardware_id_hash'])
                    ->active()
                    ->get()
                    ->each(fn (SigningDevice $old) => $this->registry->revoke($old));
            }

            $row = SigningDevice::create([
                'uuid'               => (string) Str::uuid(),
                'user_id'            => $userId,
                'label'              => $device['label'] ?: ($device['model'] ?: 'Computer'),
                'public_key'         => $identity['pem'],
                'key_fingerprint'    => $identity['fingerprint'],
                'algorithm'          => $identity['algorithm'],
                'kind'               => 'agent',
                'protection'         => $claim['protection'],
                'user_presence'      => (bool) $claim['user_presence'],
                // Phase 5: verify $claim['attestation'] against the Microsoft
                // TPM roots. Until then nothing is marked attested.
                'attested'           => false,
                'device_type'        => 'desktop',
                'form_factor'        => $device['form_factor'],
                'platform'           => $device['platform'],
                'model'              => $device['model'],
                'user_agent'         => sprintf('Kukux Sign Agent %s (%s %s)', $claim['agent_version'], $device['platform'], $device['os_version']),
                'hardware_id_hash'   => $device['hardware_id_hash'] ?: null,
                'agent_version'      => $claim['agent_version'],
                'session_public_key' => $session['pem'],
                'status'             => 'active',
                'registered_ip'      => $claim['ip'] ?? null,
                'approved_at'        => now(),
            ]);

            $pairing->update(['status' => 'confirmed', 'device_id' => $row->id]);

            SignatureAudit::record(SignatureAudit::AGENT_PAIRED, [
                'subject_user_id' => $userId,
                'device_id'       => $row->id,
                'context'         => ['pairing' => $pairing->uuid, 'protection' => $row->protection],
            ]);

            $this->registry->announce($row);

            return $row;
        });
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

        $replaces = empty($device['hardware_id_hash']) ? null : SigningDevice::query()
            ->where('user_id', $pairing->user_id)
            ->where('kind', 'agent')
            ->where('hardware_id_hash', $device['hardware_id_hash'])
            ->active()
            ->first();

        return [
            'label'      => $device['label'] ?: $device['model'],
            'model'      => $device['model'],
            'platform'   => trim($device['platform'].' '.$device['os_version']),
            'protection' => match ($claim['protection']) {
                'secure_enclave' => 'Secure Enclave',
                'tpm'            => 'TPM',
                default          => 'Software key',
            },
            'presence'   => (bool) $claim['user_presence'],
            'version'    => $claim['agent_version'],
            'replaces'   => $replaces?->displayName(),
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
            'require_presence' => (bool) config('signature.devices.agent.require_presence', true),
            'expires_at'       => $pairing->expires_at->toIso8601String(),
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

            $pollSecret = AgentServer::token();

            $pairing->update([
                'status'           => 'awaiting_confirmation',
                'poll_secret_hash' => hash('sha256', $pollSecret),
                'claim'            => [
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
                        'form_factor'      => in_array($device['form_factor'] ?? null, ['laptop', 'desktop'], true) ? $device['form_factor'] : 'unknown',
                        'label'            => $text('label', 120),
                        'hardware_id_hash' => preg_match('/^[0-9a-f]{64}$/', $hardware) ? $hardware : '',
                    ],
                    'ip'                  => $ip,
                ],
            ]);

            return ['status' => 'awaiting_confirmation', 'poll_secret' => $pollSecret];
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
                    'status' => 'confirmed',
                    'device' => ['uuid' => $device->uuid, 'label' => $device->displayName()],
                    'token'  => $token,
                ];
            }

            if (in_array($pairing->status, ['pending', 'awaiting_confirmation'], true) && $pairing->isExpired()) {
                $pairing->update(['status' => 'expired']);
            }

            return ['status' => $pairing->status];
        });
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]/', '', $code)));
    }
}
