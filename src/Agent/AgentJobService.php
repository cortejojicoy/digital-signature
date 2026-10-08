<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;

/**
 * Signing jobs: "approve this signing on your computer".
 *
 *   createForDocument()  web     — when a signer with a paired computer signs
 *   claim()              agent   — consumes the one-time link token
 *   complete()           agent   — identity-key receipt over the document hash
 *   reject()             agent   — the user declined, or the OS prompt was cancelled
 *   approvalFor()        server  — the completed job a signature may spend
 *   consume()            server  — spent on that signature
 *
 * Wire contract: digital-signature-agent/docs/protocol.md "Signing jobs".
 */
class AgentJobService
{
    public const REJECT_REASONS = ['declined', 'os_prompt_cancelled', 'invalid_job'];

    /**
     * @return array{0: AgentJob, 1: string}  The job and its kukuxsign:// link.
     */
    public function createForDocument(int $userId, string $documentHash, ?Signable $signable = null): array
    {
        return $this->create($userId, 'sign_receipt', $signable?->getSignableTitle() ?: 'Document', $documentHash, [
            'signable' => $signable,
        ]);
    }

    /**
     * Any job the agent approves with its identity key.
     *
     *   sign_receipt  a document hash (above, and hub sign requests)
     *   login         hub sign-in (Hub\HubLoginService)
     *   transfer      moving a signature to a new computer (Hub\IdentityTransfer)
     *
     * @param  array{signable?: ?Signable, requesting_app?: ?string, meta?: array<string, mixed>, ttl?: int}  $options
     * @return array{0: AgentJob, 1: string}  The job and its kukuxsign:// link.
     */
    public function create(int $userId, string $purpose, string $title, string $payloadHash, array $options = []): array
    {
        $token = AgentServer::token();
        $signable = $options['signable'] ?? null;

        $job = AgentJob::create([
            'uuid'            => (string) Str::uuid(),
            'user_id'         => $userId,
            'purpose'         => $purpose,
            'title'           => Str::limit($title, 250, '…'),
            'signable_type'   => $signable ? get_class($signable) : null,
            'signable_id'     => $signable?->getSignableId(),
            'payload_hash'    => $payloadHash,
            'nonce'           => AgentServer::token(),
            'link_token_hash' => hash('sha256', $token),
            'status'          => 'pending',
            'requesting_app'  => $options['requesting_app'] ?? null,
            'meta'            => $options['meta'] ?? null,
            'expires_at'      => now()->addSeconds((int) ($options['ttl'] ?? config('signature.devices.agent.job_ttl', 300))),
        ]);

        return [$job, $this->link($job, $token)];
    }

    public function link(AgentJob $job, string $linkToken): string
    {
        return sprintf('%s://job/%s?t=%s&s=%s', AgentServer::scheme(), $job->uuid, $linkToken, AgentServer::id());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentApiException
     */
    public function claim(SigningDevice $device, string $uuid, string $linkToken): array
    {
        return DB::transaction(function () use ($device, $uuid, $linkToken) {
            $job = $this->ownJob($device, $uuid);

            $this->expireIfDue($job);

            if ($job->status !== 'pending') {
                throw new AgentApiException(409, 'job_unavailable', "This signing request is {$job->status}.");
            }

            if ($job->link_token_hash === null || ! hash_equals($job->link_token_hash, hash('sha256', $linkToken))) {
                throw new AgentApiException(403, 'invalid_link_token', 'This link is not valid for that signing request.');
            }

            $job->update([
                'status'          => 'claimed',
                'device_id'       => $device->id,
                'link_token_hash' => null,          // single use
                'claimed_at'      => now(),
            ]);

            event(new AgentJobUpdated($job));

            return $this->payload($job);
        });
    }

    /**
     * What the agent receives for a claimed job. `requesting_app` and the
     * purpose's extra block (login, transfer) appear only when set, so a
     * standalone server's payload is exactly what it always was.
     *
     * @return array<string, mixed>
     */
    public function payload(AgentJob $job): array
    {
        $signer = $job->user;

        $payload = [
            'uuid'         => $job->uuid,
            'purpose'      => $job->purpose,
            'status'       => $job->status,
            'nonce'        => $job->nonce,
            'user_id'      => (string) $job->user_id,
            'payload_hash' => $job->payload_hash,
            'document'     => ['title' => $job->title],
            'signer'       => ['name' => (string) ($signer->name ?? $signer->email ?? '')],
            'expires_at'   => $job->expires_at->toIso8601String(),
        ];

        if (filled($job->requesting_app)) {
            $payload['requesting_app'] = ['name' => (string) $job->requesting_app];
        }

        $meta = (array) ($job->meta ?? []);

        if ($job->purpose === 'login' && isset($meta['login'])) {
            $payload['login'] = $meta['login'];
        }

        if ($job->purpose === 'transfer' && isset($meta['transfer'])) {
            $payload['transfer'] = $meta['transfer'];
        }

        return $payload;
    }

    /**
     * @throws AgentApiException
     */
    public function complete(SigningDevice $device, string $uuid, string $proof): array
    {
        $job = AgentJob::query()->where('uuid', $uuid)->first();

        if ($job === null || (int) $job->device_id !== (int) $device->id || $job->status !== 'claimed') {
            throw new AgentApiException(409, 'job_unavailable', 'This signing request is not claimed by this computer.');
        }

        if ($this->expireIfDue($job)) {
            throw new AgentApiException(409, 'job_unavailable', 'This signing request has expired.');
        }

        $signature = base64_decode($proof, true);
        $message = DeviceProofVerifier::message($job->purpose, $job->nonce, $job->user_id, $job->payload_hash);

        if ($signature === false || ! DeviceProofVerifier::verify($device->public_key, $device->algorithm, $message, $signature, 'der')) {
            throw new AgentApiException(422, 'invalid_proof', 'The signing proof did not verify.');
        }

        $job->update(['status' => 'completed', 'completed_at' => now()]);

        $device->forceFill(['last_used_at' => now(), 'last_used_ip' => request()->ip()])->save();

        SignatureAudit::record(SignatureAudit::AGENT_APPROVED, [
            'subject_user_id' => $job->user_id,
            'actor_user_id'   => $job->user_id,
            'device_id'       => $device->id,
            'context'         => ['job' => $job->uuid, 'payload_hash' => $job->payload_hash],
        ]);

        event(new AgentJobUpdated($job));

        return ['status' => 'completed'];
    }

    /**
     * @throws AgentApiException
     */
    public function reject(SigningDevice $device, string $uuid, string $reason): array
    {
        $job = AgentJob::query()->where('uuid', $uuid)->first();

        if ($job === null || (int) $job->user_id !== (int) $device->user_id || ($job->device_id !== null && (int) $job->device_id !== (int) $device->id)) {
            throw new AgentApiException(404, 'job_not_found', 'Signing request not found.');
        }

        if (in_array($job->status, ['pending', 'claimed'], true)) {
            $job->update([
                'status'    => 'rejected',
                'reason'    => in_array($reason, self::REJECT_REASONS, true) ? $reason : 'declined',
                'device_id' => $job->device_id ?? $device->id,
            ]);

            event(new AgentJobUpdated($job));
        }

        return ['status' => $job->status];
    }

    /**
     * What the waiting web page polls.
     *
     * @return array<string, mixed>|null  Null when the job is not this user's.
     */
    public function webStatus(int $userId, string $uuid): ?array
    {
        $job = AgentJob::query()->where('uuid', $uuid)->where('user_id', $userId)->with('device')->first();

        if ($job === null) {
            return null;
        }

        $this->expireIfDue($job);

        return [
            'status' => $job->status,
            'reason' => $job->reason,
            'device' => $job->device ? [
                'label'      => $job->device->displayName(),
                'protection' => $job->device->protectionLabel(),
            ] : null,
        ];
    }

    /**
     * A completed, unspent approval of exactly this document, from an agent
     * that is still active.
     */
    public function approvalFor(int $userId, string $documentHash): ?AgentJob
    {
        $job = AgentJob::query()
            ->where('user_id', $userId)
            ->where('purpose', 'sign_receipt')
            ->where('status', 'completed')
            ->where('payload_hash', $documentHash)
            ->whereNull('consumed_at')
            ->where('completed_at', '>=', now()->subSeconds((int) config('signature.devices.agent.job_ttl', 300)))
            ->with('device')
            ->latest('completed_at')
            ->first();

        return $job?->device?->isActive() && $job->device->kind === 'agent' ? $job : null;
    }

    public function consume(AgentJob $job, Signature $signature): void
    {
        $job->update(['consumed_at' => now(), 'signature_id' => $signature->id]);
    }

    /**
     * @throws AgentApiException
     */
    private function ownJob(SigningDevice $device, string $uuid): AgentJob
    {
        $job = AgentJob::query()->where('uuid', $uuid)->lockForUpdate()->first();

        if ($job === null || (int) $job->user_id !== (int) $device->user_id) {
            throw new AgentApiException(404, 'job_not_found', 'Signing request not found.');
        }

        return $job;
    }

    private function expireIfDue(AgentJob $job): bool
    {
        if (in_array($job->status, ['pending', 'claimed'], true) && $job->isExpired()) {
            $job->update(['status' => 'expired', 'link_token_hash' => null]);
            event(new AgentJobUpdated($job));
        }

        return $job->status === 'expired';
    }
}
