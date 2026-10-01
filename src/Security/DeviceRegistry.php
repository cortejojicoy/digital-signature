<?php

namespace Kukux\DigitalSignature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\DeviceRegistered;
use Kukux\DigitalSignature\Events\DeviceRevoked;
use Kukux\DigitalSignature\Exceptions\AgentApprovalRequiredException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\AgentToken;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Notifications\NewSigningDeviceNotification;
use Kukux\DigitalSignature\Support\DeviceDescriptor;

/**
 * The package's `authorized_keys`: which keys may sign as which user, and
 * which key is present in the current request.
 *
 * How a browser becomes "the device in this request":
 *
 *   1. challenge()  — the server issues a single-use nonce.
 *   2. attest()     — the browser signs `v1|attest|<nonce>|<user>|<key fp>`
 *                     with its non-extractable key. The proof is verified and
 *                     the key is remembered in the session for
 *                     `attestation_ttl` seconds.
 *   3. forSigning() — called when a signature is created or used. The first
 *                     time a verified key signs, it is registered as a
 *                     device; every time, it is recorded on the signature.
 *
 * Registration waits for step 3 on purpose: opening a panel page should not
 * add a device to someone's account, signing should.
 *
 * Scoped to the request (see the service provider) so the resolved device is
 * looked up once per request, and never leaks between queued jobs.
 */
class DeviceRegistry
{
    public const PURPOSE_ATTEST = 'attest';

    public const SESSION_KEY = 'signature.device';

    public const SKIP_KEY = 'signature.agent_skip';

    private ?SigningDevice $resolved = null;

    private ?SigningDevice $approvedAgent = null;

    private ?AgentJob $approvedJob = null;

    private bool $resolvedLoaded = false;

    // -------------------------------------------------------------------------
    // Challenge / attestation
    // -------------------------------------------------------------------------

    /**
     * @return array{nonce: string, user_id: int, expires_in: int}
     */
    public function challenge(int $userId, string $purpose = self::PURPOSE_ATTEST, string $payloadHash = ''): array
    {
        $ttl = (int) config('signature.devices.challenge_ttl', 120);
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Cache::put($this->challengeKey($nonce), [
            'user_id'      => $userId,
            'purpose'      => $purpose,
            'payload_hash' => $payloadHash,
        ], $ttl);

        // user_id goes back to the client because it is part of the message
        // the device signs; it is the caller's own id, so nothing leaks.
        return ['nonce' => $nonce, 'user_id' => $userId, 'expires_in' => $ttl];
    }

    /**
     * Verify a browser's proof that it holds the private key for $publicKey,
     * and remember the key for this session.
     *
     * @param  array{public_key: string, signature: string, nonce: string, signature_format?: string, label?: string, hints?: array<string, mixed>}  $input
     * @return array{status: string, device: ?SigningDevice, fingerprint: string, expires_in: int}
     *
     * @throws InvalidArgumentException when the key, nonce or proof is invalid.
     */
    public function attest(int $userId, array $input): array
    {
        $key = DeviceProofVerifier::parsePublicKey((string) ($input['public_key'] ?? ''));

        $nonce = (string) ($input['nonce'] ?? '');

        if (! $this->consumeChallenge($nonce, $userId, self::PURPOSE_ATTEST)) {
            throw new InvalidArgumentException('The device challenge is unknown, expired, or already used.');
        }

        $signature = base64_decode((string) ($input['signature'] ?? ''), true);
        $format = ($input['signature_format'] ?? 'raw') === 'der' ? 'der' : 'raw';
        $message = DeviceProofVerifier::message(self::PURPOSE_ATTEST, $nonce, $userId, $key['fingerprint']);

        if ($signature === false || ! DeviceProofVerifier::verify($key['pem'], $key['algorithm'], $message, $signature, $format)) {
            throw new InvalidArgumentException('The device proof does not verify against its public key.');
        }

        $existing = SigningDevice::query()
            ->where('user_id', $userId)
            ->where('key_fingerprint', $key['fingerprint'])
            ->first();

        if ($existing?->isRevoked()) {
            $this->forget();

            return ['status' => 'revoked', 'device' => $existing, 'fingerprint' => $key['fingerprint'], 'expires_in' => 0];
        }

        $request = request();
        $hints = is_array($input['hints'] ?? null) ? $input['hints'] : [];
        $descriptor = DeviceDescriptor::fromUserAgent($request->userAgent(), $hints);
        $ttl = (int) config('signature.devices.attestation_ttl', 900);

        $request->session()->put(self::SESSION_KEY, [
            'user_id'     => $userId,
            'fingerprint' => $key['fingerprint'],
            'public_key'  => $key['pem'],
            'algorithm'   => $key['algorithm'],
            'label'       => $this->cleanLabel($input['label'] ?? null),
            'device_type' => $descriptor->deviceType,
            'platform'    => $descriptor->platform,
            'browser'     => $descriptor->browser,
            'user_agent'  => $request->userAgent(),
            'verified_at' => now()->getTimestamp(),
        ]);

        $this->resolvedLoaded = false;

        return [
            'status'      => $existing ? 'registered' : 'unregistered',
            'device'      => $existing,
            'fingerprint' => $key['fingerprint'],
            'expires_in'  => $ttl,
        ];
    }

    /**
     * The key this session has proven it holds, if the proof is still fresh
     * and belongs to $userId.
     *
     * @return array<string, mixed>|null
     */
    public function verifiedKey(int $userId): ?array
    {
        if (! config('signature.devices.enabled', true) || ! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $key = $request->session()->get(self::SESSION_KEY);

        if (! is_array($key) || (int) ($key['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $ttl = (int) config('signature.devices.attestation_ttl', 900);

        if ((int) ($key['verified_at'] ?? 0) + $ttl < now()->getTimestamp()) {
            return null;
        }

        return $key;
    }

    public function forget(): void
    {
        if (app()->bound('request') && request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }

        $this->resolved = null;
        $this->resolvedLoaded = false;
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * The registered device behind this session's verified key, without
     * registering anything. What audits and "Signing from…" read.
     */
    public function current(?int $userId = null): ?SigningDevice
    {
        $userId ??= auth()->id();

        if (! $userId) {
            return null;
        }

        if ($this->resolvedLoaded && $this->resolved?->user_id === (int) $userId) {
            return $this->resolved;
        }

        $key = $this->verifiedKey((int) $userId);

        $device = $key === null ? null : SigningDevice::query()
            ->where('user_id', $userId)
            ->where('key_fingerprint', $key['fingerprint'])
            ->first();

        $this->resolved = $device;
        $this->resolvedLoaded = true;

        return $device;
    }

    /**
     * The device a signature is being created or used on — registering the
     * verified key as a device the first time it signs — with the configured
     * policy applied.
     *
     * @param  Signature|null  $source  When *using* an existing signature: the
     *                                  signature being used, for usage_policy.
     * @param  string|null  $documentHash  When signing a document: the hash of
     *                                     the exact PDF, which an agent job
     *                                     must have approved.
     *
     * @throws UnregisteredDeviceException when a device is required and absent,
     *                                     revoked, or over the per-user limit.
     * @throws MachineBindingException     under creation_device_only, when this
     *                                     is not the device $source was made on.
     * @throws AgentApprovalRequiredException  waiting on the user's computer.
     */
    public function forSigning(
        int $userId,
        ?Signature $source = null,
        ?string $documentHash = null,
        ?Signable $signable = null,
    ): ?SigningDevice {
        if (! config('signature.devices.enabled', true)) {
            return null;
        }

        // A paired computer outranks the browser: its key is in hardware and
        // the OS made the owner confirm. Only for using a signature on a
        // document — that is what an agent job approves.
        $device = $documentHash !== null
            ? $this->agentApproval($userId, $documentHash, $signable)
            : null;

        $device ??= $this->resolveOrRegister($userId);

        if ($device === null) {
            $mode = config('signature.devices.require', 'off');

            if ($mode === 'enforce') {
                throw new UnregisteredDeviceException(
                    'This browser has not been verified as one of your signing devices. '
                    .'Reload the page and try again. If this keeps happening, your browser may be blocking the storage the key needs.'
                );
            }

            if ($mode === 'warn') {
                Log::warning('Signature act without a verified signing device.', ['user_id' => $userId]);
            }
        }

        if (
            $source !== null
            && config('signature.devices.usage_policy', 'any_registered') === 'creation_device_only'
            && $source->device_id !== null
            && $device?->id !== $source->device_id
        ) {
            $origin = SigningDevice::find($source->device_id);

            throw new MachineBindingException(
                'This signature can only be used on the device it was created on'
                .($origin ? ' ('.$origin->displayName().')' : '').'.'
            );
        }

        if ($device !== null) {
            $device->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => request()->ip(),
            ])->save();
        }

        return $device;
    }

    // -------------------------------------------------------------------------
    // Desktop agent approval
    // -------------------------------------------------------------------------

    /**
     * The agent device that approved signing $documentHash, or null when the
     * agent is not involved. Throws to ask for approval when it should be.
     *
     * Once an approval is spent, it covers the rest of this request: one
     * document signed in several slots is one act of signing, but every slot
     * changes the document's hash, so only the first can match the job.
     *
     * @throws AgentApprovalRequiredException
     * @throws UnregisteredDeviceException  approval = enforce, no paired computer.
     */
    private function agentApproval(int $userId, string $documentHash, ?Signable $signable): ?SigningDevice
    {
        if (! AgentServer::enabled()) {
            return null;
        }

        if ($this->approvedAgent !== null && (int) $this->approvedAgent->user_id === $userId) {
            return $this->approvedAgent;
        }

        $mode = config('signature.devices.agent.approval', 'prefer');

        if ($mode === 'off') {
            return null;
        }

        $jobs = app(AgentJobService::class);

        if ($job = $jobs->approvalFor($userId, $documentHash)) {
            $this->approvedAgent = $job->device;
            $this->approvedJob = $job;

            return $job->device;
        }

        $agent = SigningDevice::query()
            ->where('user_id', $userId)
            ->where('kind', 'agent')
            ->active()
            ->latest('last_used_at')
            ->first();

        if ($agent === null) {
            if ($mode === 'enforce') {
                throw new UnregisteredDeviceException(
                    'Signing documents requires approval on a paired computer. '
                    .'Pair the Kukux Sign Agent from your signing devices first.'
                );
            }

            return null;
        }

        if ($mode === 'prefer' && $this->skippingAgent($userId)) {
            return null;
        }

        [$job, $link] = $jobs->createForDocument($userId, $documentHash, $signable);

        throw new AgentApprovalRequiredException($job, $link, $agent, canSkip: $mode === 'prefer');
    }

    /**
     * Mark the approval this request used as spent on $signature.
     */
    public function settleApproval(Signature $signature): void
    {
        if ($this->approvedJob !== null && $this->approvedJob->consumed_at === null) {
            app(AgentJobService::class)->consume($this->approvedJob, $signature);
        }
    }

    /**
     * "Sign in the browser instead" — for the next few minutes, document
     * signing does not ask this user's agent.
     */
    public function skipAgent(int $userId): void
    {
        request()->session()->put(self::SKIP_KEY, [
            'user_id' => $userId,
            'until'   => now()->addSeconds((int) config('signature.devices.agent.skip_ttl', 120))->getTimestamp(),
        ]);
    }

    private function skippingAgent(int $userId): bool
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return false;
        }

        $skip = request()->session()->get(self::SKIP_KEY);

        return is_array($skip)
            && (int) ($skip['user_id'] ?? 0) === $userId
            && (int) ($skip['until'] ?? 0) >= now()->getTimestamp();
    }

    private function resolveOrRegister(int $userId): ?SigningDevice
    {
        $key = $this->verifiedKey($userId);

        if ($key === null) {
            return null;
        }

        $device = $this->current($userId);

        if ($device?->isRevoked()) {
            $this->forget();

            throw new UnregisteredDeviceException(
                'This device was revoked from your signing devices and can no longer sign.'
            );
        }

        if ($device !== null) {
            return $device;
        }

        return $this->register($userId, $key);
    }

    /**
     * @param  array<string, mixed>  $key  A verifiedKey() payload.
     */
    private function register(int $userId, array $key): SigningDevice
    {
        $this->assertCapacity($userId);

        $attributes = [
            'uuid'            => (string) Str::uuid(),
            'user_id'         => $userId,
            'label'           => '',
            'public_key'      => $key['public_key'],
            'key_fingerprint' => $key['fingerprint'],
            'algorithm'       => $key['algorithm'],
            'kind'            => 'browser',
            'protection'      => 'browser',
            'device_type'     => $key['device_type'] ?? 'unknown',
            'platform'        => $key['platform'] ?? null,
            'browser'         => $key['browser'] ?? null,
            'user_agent'      => $key['user_agent'] ?? null,
            'status'          => 'active',
            'registered_ip'   => request()->ip(),
            'approved_at'     => now(),
        ];

        try {
            $device = SigningDevice::create($attributes);
        } catch (QueryException $e) {
            // Two tabs registering the same key at once: the unique index
            // lets exactly one win, and the other uses its row.
            $device = SigningDevice::query()
                ->where('user_id', $userId)
                ->where('key_fingerprint', $key['fingerprint'])
                ->first() ?? throw $e;

            return $device;
        }

        $device->forceFill(['label' => $key['label'] ?: $device->describe()])->save();

        $this->resolved = $device;
        $this->resolvedLoaded = true;

        $this->announce($device);

        return $device;
    }

    /**
     * @throws UnregisteredDeviceException at signature.devices.max_per_user.
     */
    public function assertCapacity(int $userId): void
    {
        $max = (int) config('signature.devices.max_per_user', 10);

        if ($max > 0 && SigningDevice::query()->where('user_id', $userId)->active()->count() >= $max) {
            throw new UnregisteredDeviceException(
                "You already have {$max} signing devices. Revoke one you no longer use, then try again."
            );
        }
    }

    /**
     * Fire DeviceRegistered, and tell the owner — unless this is their first
     * device, which is starting out rather than a change worth an alert.
     */
    public function announce(SigningDevice $device): void
    {
        event(new DeviceRegistered($device));

        $isFirst = ! SigningDevice::query()
            ->where('user_id', $device->user_id)
            ->whereKeyNot($device->id)
            ->exists();

        $this->notifyNewDevice($device, $isFirst);
    }

    // -------------------------------------------------------------------------
    // Management
    // -------------------------------------------------------------------------

    public function rename(SigningDevice $device, string $label): SigningDevice
    {
        $device->update(['label' => $this->cleanLabel($label) ?: $device->describe()]);

        return $device;
    }

    public function revoke(SigningDevice $device): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $device->update(['status' => 'revoked', 'revoked_at' => now()]);

        // A revoked agent loses its API access with it; its next request is
        // a 401, on which the agent deletes its keys.
        AgentToken::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($this->resolved?->is($device)) {
            $this->forget();
        }

        event(new DeviceRevoked($device));
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function consumeChallenge(string $nonce, int $userId, string $purpose, string $payloadHash = ''): bool
    {
        if ($nonce === '' || strlen($nonce) > 128) {
            return false;
        }

        $challenge = Cache::pull($this->challengeKey($nonce));

        return is_array($challenge)
            && (int) $challenge['user_id'] === $userId
            && $challenge['purpose'] === $purpose
            && hash_equals((string) $challenge['payload_hash'], $payloadHash);
    }

    private function challengeKey(string $nonce): string
    {
        return 'signature:device-challenge:'.hash('sha256', $nonce);
    }

    private function cleanLabel(mixed $label): string
    {
        return Str::limit(trim(strip_tags((string) $label)), 120, '');
    }

    private function notifyNewDevice(SigningDevice $device, bool $isFirst): void
    {
        if ($isFirst || ! config('signature.devices.notify_on_new_device', true)) {
            return;
        }

        $user = $device->user;

        if ($user === null) {
            return;
        }

        // A mail outage must not turn "sign this document" into an error.
        try {
            NotificationFacade::send($user, new NewSigningDeviceNotification($device));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
