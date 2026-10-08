<?php

namespace Kukux\DigitalSignature\Hub\Signing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Exceptions\CertificateRevokedException;
use Kukux\DigitalSignature\Hub\Api\HubApiException;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubSignRequest;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Security\CrlValidator;
use Kukux\DigitalSignature\Services\CertificateService;

/**
 * An app asks the hub to sign a document hash for a person (A11):
 *
 *   create()        checks the signer, opens an agent job ("performance asks:
 *                   <title>"), returns the approval link
 *   onJobUpdated()  the agent approved → sign the digest into a CMS with the
 *                   person's certificate; declined / expired → say so
 *   find()          the app polls; webhooks say the same thing sooner
 *
 * Checks at request time, and again at approval (things change in between):
 * person known and on a current account, identity verified, an active
 * specimen whose hash is the one the app stamped, certificate not revoked.
 */
class HubSignRequestService
{
    /** Internal: approved by the agent, CMS being made. Reported as pending. */
    private const SIGNING = 'approved';

    public function __construct(
        private readonly People $people,
        private readonly SpecimenService $specimens,
        private readonly SignerCertificates $certificates,
        private readonly AgentJobService $jobs,
        private readonly HubNotifier $notifier,
    ) {}

    /**
     * @param  array{sub: string, document_hash: string, specimen_hash: string, title: string, slot?: ?string, capacity?: ?string, idempotency_key: string}  $input
     * @return array{0: HubSignRequest, 1: ?string, 2: bool}  the request, its approval link (when
     *                                                       still usable), and whether it already existed
     *
     * @throws HubApiException
     */
    public function create(HubApp $app, array $input): array
    {
        $existing = $this->byIdempotencyKey($app, $input['idempotency_key']);

        if ($existing !== null) {
            return $this->replay($existing, $input);
        }

        [$userId, $signature] = $this->check($app, $input);

        $ttl = (int) config('signature.hub.sign_request_ttl', 300);
        $title = Str::limit(trim($input['title']), 250, '…');
        $slot = filled($input['slot'] ?? null) ? trim($input['slot']) : null;

        try {
            [$request, $link] = DB::transaction(function () use ($app, $input, $userId, $signature, $ttl, $title, $slot) {
                $request = HubSignRequest::create([
                    'uuid'            => (string) Str::uuid(),
                    'app_id'          => $app->id,
                    'personnel_key'   => $input['sub'],
                    'user_id'         => $userId,
                    'signature_id'    => $signature->id,
                    'idempotency_key' => $input['idempotency_key'],
                    'document_hash'   => $input['document_hash'],
                    'specimen_hash'   => $input['specimen_hash'],
                    'title'           => $title,
                    'slot'            => $slot,
                    'capacity'        => filled($input['capacity'] ?? null) ? trim($input['capacity']) : null,
                    'status'          => 'pending',
                    'expires_at'      => now()->addSeconds($ttl),
                ]);

                [$job, $link] = $this->jobs->create(
                    $userId,
                    'sign_receipt',
                    $slot !== null ? "{$slot} · {$title}" : $title,
                    $input['document_hash'],
                    ['requesting_app' => $app->name, 'ttl' => $ttl],
                );

                // The link's token is stored only hashed on the job. Keep an
                // encrypted copy so a retry with the same idempotency key
                // gets the link back (R1); it is single-use anyway.
                $job->update(['meta' => [
                    'hub_sign_request' => $request->uuid,
                    'approval_link'    => Crypt::encryptString($link),
                ]]);

                $request->update(['agent_job_id' => $job->id, 'expires_at' => $job->expires_at]);

                return [$request, $link];
            });
        } catch (UniqueConstraintViolationException) {
            // The same key raced in from a retry: answer with the winner.
            return $this->replay($this->byIdempotencyKey($app, $input['idempotency_key']), $input);
        }

        SignatureAudit::record(SignatureAudit::HUB_SIGN_REQUESTED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => null,
            'actor_type'      => 'system',
            'signature_id'    => $signature->id,
            'app'             => $app->client_id,
            'personnel_key'   => $input['sub'],
            'context'         => $this->auditContext($request) + ['job' => $request->agentJob?->uuid],
        ]);

        return [$request, $link, false];
    }

    /** An app's own request by its public id. */
    public function find(HubApp $app, string $uuid): ?HubSignRequest
    {
        $request = HubSignRequest::query()->where('uuid', $uuid)->where('app_id', $app->id)->first();

        if ($request !== null) {
            $this->expireIfDue($request);
        }

        return $request?->refresh();
    }

    /**
     * What the API returns for a request.
     *
     * @return array<string, mixed>
     */
    public function present(HubSignRequest $request): array
    {
        $status = $request->status === self::SIGNING ? 'pending' : $request->status;

        return array_filter([
            'id'                      => $request->uuid,
            'status'                  => $status,
            'refusal_reason'          => $status === 'signed' ? null : $request->refusal_reason,
            'cms'                     => $status === 'signed' ? $request->cms : null,
            'certificate_fingerprint' => $status === 'signed' ? $request->certificate_fingerprint : null,
            'signed_at'               => $request->signed_at?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    /**
     * The agent job behind a request changed. Wired to AgentJobUpdated.
     */
    public function onJobUpdated(AgentJob $job): void
    {
        if ($job->purpose !== 'sign_receipt') {
            return;
        }

        $request = HubSignRequest::query()->where('agent_job_id', $job->id)->first();

        if ($request === null || $request->status !== 'pending') {
            return;
        }

        match ($job->status) {
            'completed' => $this->sign($request, $job),
            'rejected'  => $this->finish($request, 'declined', $job->reason ?: 'declined', SignatureAudit::HUB_SIGN_DECLINED),
            'expired'   => $this->finish($request, 'expired', null, SignatureAudit::HUB_SIGN_REFUSED, 'expired'),
            default     => null,
        };
    }

    /**
     * Pending requests past their deadline whose agent job nobody touched
     * (an unclaimed job only expires when something looks at it).
     * `signature:hub-webhooks` runs this every minute.
     */
    public function expireDue(): int
    {
        $expired = 0;

        HubSignRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->limit(500)
            ->get()
            ->each(function (HubSignRequest $request) use (&$expired) {
                $expired += (int) $this->expireIfDue($request);
            });

        return $expired;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: Signature}
     *
     * @throws HubApiException
     */
    private function check(HubApp $app, array $input): array
    {
        $sub = $input['sub'];
        $person = $this->people->find($sub);

        if ($person === null) {
            $this->refuse($app, $input, null, 'unknown_person', 'This person is not in the personnel directory.');
        }

        if ($this->people->isSeparated($sub, $person)) {
            $this->refuse($app, $input, null, 'separated', 'This person has left the university and can no longer sign.');
        }

        $identity = $this->people->identity($sub);

        if ($identity === null || ! $identity->isVerified()) {
            $this->refuse($app, $input, $identity?->user_id, 'not_verified', 'This person has no verified hub account yet.');
        }

        $userId = (int) $identity->user_id;
        $signature = $this->specimens->current($userId);

        if ($signature === null) {
            $this->refuse($app, $input, $userId, 'no_signature', 'This person has no active signature at the hub.');
        }

        if (! hash_equals((string) $signature->image_hash, $input['specimen_hash'])) {
            $this->refuse($app, $input, $userId, 'specimen_changed', 'The signature image changed at the hub. Re-pull it, re-stamp and resubmit.', 409, [
                'current_specimen_hash' => $signature->image_hash,
            ]);
        }

        if ($this->certificates->isRevoked($userId)) {
            $this->refuse($app, $input, $userId, 'certificate_revoked', 'This person\'s signing certificate is revoked.');
        }

        return [$userId, $signature];
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws HubApiException
     */
    private function refuse(HubApp $app, array $input, ?int $userId, string $reason, string $message, int $status = 422, array $extra = []): never
    {
        SignatureAudit::record(SignatureAudit::HUB_SIGN_REFUSED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => null,
            'actor_type'      => 'system',
            'app'             => $app->client_id,
            'personnel_key'   => $input['sub'],
            'context'         => [
                'reason'          => $reason,
                'title'           => $input['title'],
                'slot'            => $input['slot'] ?? null,
                'capacity'        => $input['capacity'] ?? null,
                'document_hash'   => $input['document_hash'],
                'specimen_hash'   => $input['specimen_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ] + $extra,
        ]);

        throw new HubApiException($status, $reason, $message, $extra);
    }

    /**
     * @return array{0: HubSignRequest, 1: ?string, 2: bool}
     *
     * @throws HubApiException
     */
    private function replay(HubSignRequest $existing, array $input): array
    {
        if ($existing->personnel_key !== $input['sub'] || $existing->document_hash !== $input['document_hash']) {
            throw new HubApiException(409, 'idempotency_conflict', 'This idempotency_key was already used for a different request.');
        }

        $this->expireIfDue($existing);
        $existing->refresh();

        $link = null;
        $job = $existing->agentJob;

        if ($existing->status === 'pending' && $job?->status === 'pending' && is_string($job->meta['approval_link'] ?? null)) {
            try {
                $link = Crypt::decryptString($job->meta['approval_link']);
            } catch (\Throwable) {
                $link = null;
            }
        }

        return [$existing, $link, true];
    }

    private function byIdempotencyKey(HubApp $app, string $key): ?HubSignRequest
    {
        return HubSignRequest::query()->where('app_id', $app->id)->where('idempotency_key', $key)->first();
    }

    /** Past its deadline and still pending: expire the job, which expires the request. */
    private function expireIfDue(HubSignRequest $request): bool
    {
        if ($request->status !== 'pending' || $request->expires_at->isFuture()) {
            return false;
        }

        $job = $request->agentJob;

        if ($job !== null && in_array($job->status, ['pending', 'claimed'], true)) {
            $job->update(['status' => 'expired', 'link_token_hash' => null]);
            event(new AgentJobUpdated($job));     // → onJobUpdated → finish()
        } elseif ($job === null || $job->status === 'expired') {
            $this->finish($request, 'expired', null, SignatureAudit::HUB_SIGN_REFUSED, 'expired');
        }

        return $request->refresh()->status === 'expired';
    }

    /**
     * The agent approved: make the CMS. Re-checks everything first, since
     * the specimen or certificate may have changed while the request waited.
     */
    private function sign(HubSignRequest $request, AgentJob $job): void
    {
        // Claim the request, so a duplicate event can't sign it twice.
        $claimed = HubSignRequest::query()
            ->whereKey($request->id)
            ->where('status', 'pending')
            ->update(['status' => self::SIGNING]);

        if ($claimed !== 1) {
            return;
        }

        // The approval is spent on this request, never on a local signing.
        $job->update(['consumed_at' => now()]);

        $request->refresh();
        $userId = (int) $request->user_id;

        try {
            $signature = $this->specimens->current($userId);

            $refusal = match (true) {
                ! $this->people->isVerified($userId)                                     => 'not_verified',
                $signature === null                                                     => 'no_signature',
                ! hash_equals((string) $signature->image_hash, $request->specimen_hash) => 'specimen_changed',
                $this->certificates->isRevoked($userId)                                 => 'certificate_revoked',
                default                                                                 => null,
            };

            if ($refusal !== null) {
                $this->finish($request, 'refused', $refusal, SignatureAudit::HUB_SIGN_REFUSED, $refusal, $job);

                return;
            }

            $password = $signature->getCertificatePassword();

            if ($password === null) {
                $this->finish($request, 'failed', 'no_certificate_password', SignatureAudit::HUB_SIGN_REFUSED, 'no_certificate_password', $job);

                return;
            }

            $certificates = app(CertificateService::class);
            $cert = $certificates->getOrCreate($userId, $password);
            $certData = $certificates->load($cert, $password);

            try {
                app(CrlValidator::class)->validate($certData);
            } catch (CertificateRevokedException) {
                $this->finish($request, 'refused', 'certificate_revoked', SignatureAudit::HUB_SIGN_REFUSED, 'certificate_revoked', $job);

                return;
            }

            $cms = app(DigestSigner::class)->signDigest(
                $request->document_hash,
                $certData['cert'],
                $certData['pkey'],
                array_values((array) ($certData['extracerts'] ?? [])),
                config('signature.tsa.url') ?: null,
            );
        } catch (\Throwable $e) {
            report($e);
            $this->finish($request, 'failed', 'signing_failed', SignatureAudit::HUB_SIGN_REFUSED, 'signing_failed', $job);

            return;
        }

        $request->update([
            'status'                  => 'signed',
            'refusal_reason'          => null,
            'signature_id'            => $signature->id,
            'cms'                     => base64_encode($cms),
            'certificate_fingerprint' => $cert->fingerprint,
            'signed_at'               => now(),
        ]);

        $device = $job->device;

        SignatureAudit::record(SignatureAudit::HUB_SIGNED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => $userId,
            'actor_type'      => 'user',
            'signature_id'    => $signature->id,
            'device_id'       => $job->device_id,
            'app'             => $request->app?->client_id,
            'personnel_key'   => $request->personnel_key,
            'context'         => $this->auditContext($request) + [
                'job'                     => $job->uuid,
                'device_id'               => $job->device_id,
                'purpose'                 => $job->purpose,
                'user_presence'           => $device?->protectionLabel(),
                'specimen_version_id'     => $signature->hub_version_id,
                'certificate_fingerprint' => $cert->fingerprint,
            ],
        ]);

        $this->notifier->signRequestCompleted($request);
    }

    /**
     * Any ending but `signed`: record it, audit it, tell the app.
     */
    private function finish(HubSignRequest $request, string $status, ?string $reason, string $auditEvent, ?string $auditReason = null, ?AgentJob $job = null): void
    {
        $updated = HubSignRequest::query()
            ->whereKey($request->id)
            ->whereIn('status', ['pending', self::SIGNING])
            ->update(['status' => $status, 'refusal_reason' => $reason, 'updated_at' => now()]);

        if ($updated !== 1) {
            return;
        }

        $request->refresh();
        $job ??= $request->agentJob;

        SignatureAudit::record($auditEvent, [
            'subject_user_id' => $request->user_id,
            'actor_user_id'   => $status === 'declined' ? $request->user_id : null,
            'actor_type'      => $status === 'declined' ? 'user' : 'system',
            'signature_id'    => $request->signature_id,
            'device_id'       => $job?->device_id,
            'app'             => $request->app?->client_id,
            'personnel_key'   => $request->personnel_key,
            'context'         => $this->auditContext($request) + array_filter([
                'outcome'   => $status,
                'reason'    => $auditReason ?? $reason,
                'job'       => $job?->uuid,
                'device_id' => $job?->device_id,
                'purpose'   => $job?->purpose,
            ], fn ($v) => $v !== null),
        ]);

        $this->notifier->signRequestCompleted($request);
    }

    /**
     * The "where" of the admin audit trail (plan 1.7).
     *
     * @return array<string, mixed>
     */
    private function auditContext(HubSignRequest $request): array
    {
        return [
            'request'       => $request->uuid,
            'app_name'      => $request->app?->name,
            'title'         => $request->title,
            'slot'          => $request->slot,
            'capacity'      => $request->capacity,
            'document_hash' => $request->document_hash,
            'specimen_hash' => $request->specimen_hash,
        ];
    }
}
