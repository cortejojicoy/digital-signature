<?php

namespace Kukux\DigitalSignature\Client;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Client\Exceptions\HubApprovalRequiredException;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubNotFoundException;
use Kukux\DigitalSignature\Client\Exceptions\HubRefusedException;
use Kukux\DigitalSignature\Client\Exceptions\HubSigningException;
use Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException;
use Kukux\DigitalSignature\Client\Exceptions\MirrorIntegrityException;
use Kukux\DigitalSignature\Client\Exceptions\SpecimenChangedException;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Services\SignatureCaption;
use Kukux\DigitalSignature\Support\DiskPath;

/**
 * Client mode: signing a document by sending its hash to the hub (A7, R1).
 *
 * The app holds no keys. Where standalone loads a certificate and lets the PDF
 * driver sign, client mode does it in two halves around an approval on the
 * signer's computer:
 *
 *   1. awaitSignature()  stamp the mirror image into a copy of the document
 *                        with an empty signature (DeferredPdfSigner::prepare),
 *                        record a HubPendingSign, and POST the digest to the
 *                        hub. The hub answers 202 with an agent link, and this
 *                        throws HubApprovalRequiredException: the browser's
 *                        existing approval overlay opens the agent and polls.
 *   2. awaitSignature()  again, when the browser retries after the approval.
 *                        The pending request is now `signed` (the poll or the
 *                        webhook stored the CMS), so it returns, and…
 *   3. finalize()        injects the CMS into the prepared PDF and finishes
 *                        exactly as embedAndFinalize() does in standalone:
 *                        signed path, hashes, version, DocumentSigned.
 *
 * A pending request is found again by its signer and the hash of the document
 * it was made from (plus the slot, in a session), so the retry needs nothing
 * from the browser but the same call.
 *
 * **Outage (R1).** If the hub can't be reached, the prepared PDF and the row
 * are kept as `unsent` and the signer reads "Signing is unavailable. Your
 * placement is saved." `signature:hub-retry` sends them once the hub answers.
 *
 * **Changed specimen.** A `409 specimen_changed` re-pulls the mirror,
 * re-stamps with the new image and resubmits, once.
 */
class HubSigning
{
    /** Hub sign-request statuses (docs/hub/contracts.md §2.2). */
    public const HUB_STATUSES = ['pending', 'signed', 'declined', 'refused', 'expired', 'failed'];

    /** Local statuses a hub answer may still move. */
    public const OPEN_STATUSES = ['unsent', 'pending', 'approved'];

    public function __construct(
        protected HubClient $hub,
        protected HubSignatureSync $sync,
        protected DocumentIntegrity $integrity,
    ) {
    }

    // -------------------------------------------------------------------------
    // Step 1–2: get the hub's signature
    // -------------------------------------------------------------------------

    /**
     * The hub's signature over this document, once the signer has approved
     * it. Until then this throws, the way the agent approval does today.
     *
     * @param  array<string, mixed>|null  $position  first stamp (PDF points)
     * @param  array<int, array<string, mixed>>  $extraPositions
     * @param  array<string, mixed>|null  $chain  session_id / slot_key / sequence
     *
     * @throws HubApprovalRequiredException  waiting on the signer's computer
     * @throws HubSigningException  unavailable, refused, or nothing to sign with
     */
    public function awaitSignature(
        Signature $source,
        int $userId,
        Signable $signable,
        string $sourcePdfPath,
        string $sourceHash,
        ?array $position = null,
        array $extraPositions = [],
        ?array $chain = null,
    ): HubPendingSign {
        if ($source->source !== 'hub' || ! $source->hub_image_hash) {
            throw new HubSigningException(
                'Signatures here come from UPLB Signature. Sign in with UPLB Signature to fetch yours, then try again.',
                'no_mirror',
            );
        }

        $sub = HubAccount::subFor($userId) ?? throw new HubSigningException(
            'Your account is not linked to UPLB Signature yet. Sign in with UPLB Signature once, then try again.',
            'unlinked',
        );

        $request = $this->requestFor($chain);

        $existing = HubPendingSign::query()
            ->where('user_id', $userId)
            ->where('source_hash', $sourceHash)
            ->when(
                $request !== null,
                fn ($q) => $q->where('signature_request_id', $request->id),
                fn ($q) => $q->whereNull('signature_request_id'),
            )
            ->where('status', '!=', 'done')
            ->latest('id')
            ->first();

        if ($existing !== null && ($resumed = $this->resume($existing, $source)) !== null) {
            return $resumed;
        }

        $pending = $this->prepare($source, $userId, [
            'sub'             => $sub,
            'title'           => $this->titleOf($signable),
            'slot'            => $request?->slot_key ?? ($chain['slot_key'] ?? null),
            'capacity'        => $request?->role,
            'source_path'     => DiskPath::relative($sourcePdfPath),
            'position'        => $this->geometry($position ?? []),
            'extra_positions' => array_values(array_map(fn (array $p) => $this->geometry($p), array_filter($extraPositions))),
            'signable_type'   => get_class($signable),
            'signable_id'     => $signable->getSignableId(),
        ], $sourceHash, $request?->id);

        return $this->settle($this->submit($pending));
    }

    /**
     * An earlier attempt at the same document: use it, wait on it, or send
     * it again. Null when it is spent and a new one is needed.
     */
    protected function resume(HubPendingSign $pending, Signature $source): ?HubPendingSign
    {
        if (in_array($pending->status, ['pending', 'approved'], true)) {
            $pending = $this->refresh($pending);
        }

        if ($pending->status === 'signed') {
            return $pending;
        }

        if (in_array($pending->status, ['pending', 'approved'], true)) {
            $expires = $pending->payload['expires_at'] ?? null;

            if ($expires === null || now()->lt(Carbon::parse($expires))) {
                throw new HubApprovalRequiredException($pending);
            }

            $pending->update(['status' => 'expired', 'reason' => 'expired']);

            return null;
        }

        if ($pending->status === 'unsent') {
            // Stamped with an image the hub has since replaced: don't send it.
            if ($pending->specimen_hash !== $source->hub_image_hash) {
                $pending->update(['status' => 'failed', 'reason' => 'superseded']);

                return null;
            }

            return $this->settle($this->submit($pending));
        }

        // declined, refused, expired, failed: a new attempt.
        return null;
    }

    /**
     * Throw for anything but a signature in hand.
     */
    protected function settle(HubPendingSign $pending): HubPendingSign
    {
        return match ($pending->status) {
            'signed'             => $pending,
            'pending', 'approved' => throw new HubApprovalRequiredException($pending),
            'declined'           => throw new HubSigningException('The signature was declined on your computer. Nothing was signed.', 'declined'),
            default              => throw new HubSigningException(
                $this->refusalMessage($pending->reason),
                (string) ($pending->status ?: 'failed'),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    /**
     * POST one pending request to the hub and record the answer.
     *
     * Used by signing and by `signature:hub-retry`. A changed specimen is
     * handled here (re-pull, re-stamp, resubmit) when $repull allows it.
     *
     * @throws HubSigningException  unavailable (row stays unsent), refused
     */
    public function submit(HubPendingSign $pending, bool $repull = true): HubPendingSign
    {
        $payload = $pending->payload ?? [];

        $pending->forceFill(['attempts' => $pending->attempts + 1])->save();

        try {
            $answer = $this->hub->createSignRequest([
                'sub'             => (string) ($payload['sub'] ?? HubAccount::subFor((int) $pending->user_id)),
                'document_hash'   => $pending->document_hash,
                'specimen_hash'   => $pending->specimen_hash,
                'title'           => (string) ($payload['title'] ?? 'Document'),
                'slot'            => $payload['slot'] ?? null,
                'capacity'        => $payload['capacity'] ?? null,
                'idempotency_key' => $pending->idempotency_key,
            ]);
        } catch (SpecimenChangedException $e) {
            $pending->update(['status' => 'failed', 'reason' => 'specimen_changed']);

            if (! $repull) {
                throw new HubSigningException(
                    'Your signature changed at UPLB Signature while this was being signed. Try again.',
                    'specimen_changed',
                    $e,
                );
            }

            return $this->submit($this->restamp($pending), repull: false);
        } catch (HubUnavailableException $e) {
            // Kept as `unsent`: the placement and the stamped copy wait for
            // signature:hub-retry, or for the signer to try again.
            throw HubSigningException::unavailable($e);
        } catch (HubRefusedException|HubNotFoundException $e) {
            $pending->update(['status' => 'refused', 'reason' => Str::limit((string) ($e->errorCode ?? 'refused'), 60, '')]);

            SignatureAudit::record(SignatureAudit::HUB_SIGN_REFUSED, [
                'subject_user_id'      => $pending->user_id,
                'signature_request_id' => $pending->signature_request_id,
                'context'              => ['hub_request' => $pending->hub_request_id, 'reason' => $e->errorCode],
            ]);

            throw new HubSigningException($this->refusalMessage($e->errorCode, $e->getMessage()), 'refused', $e);
        } catch (HubException $e) {
            $pending->update(['status' => 'failed', 'reason' => Str::limit((string) ($e->errorCode ?? 'error'), 60, '')]);

            throw new HubSigningException('UPLB Signature could not take this request: '.$e->getMessage(), 'failed', $e);
        }

        $status = in_array($answer['status'] ?? null, self::HUB_STATUSES, true) ? $answer['status'] : 'pending';

        $pending->update([
            'hub_request_id' => $answer['id'] ?? $pending->hub_request_id,
            'agent_job_uuid' => $answer['job_uuid'] ?? $pending->agent_job_uuid,
            'status'         => $status,
            'reason'         => null,
            'payload'        => array_merge($payload, array_filter([
                'approval_link' => $answer['approval_link'] ?? null,
                'expires_at'    => $answer['expires_at'] ?? null,
            ])),
        ]);

        SignatureAudit::record(SignatureAudit::HUB_SIGN_REQUESTED, [
            'subject_user_id'      => $pending->user_id,
            'signature_request_id' => $pending->signature_request_id,
            'context'              => [
                'hub_request'   => $pending->hub_request_id,
                'document_hash' => $pending->document_hash,
                'attempt'       => $pending->attempts,
            ],
        ]);

        // The same idempotency key answers with the request as it stands,
        // which may already be signed: fetch the CMS.
        if ($status !== 'pending' && $pending->cms === null) {
            $pending = $this->refresh($pending);
        }

        return $pending;
    }

    /**
     * Ask the hub where a request stands. Never throws: an unreachable hub
     * leaves the row as it was.
     */
    public function refresh(HubPendingSign $pending): HubPendingSign
    {
        if ($pending->hub_request_id === null) {
            return $pending;
        }

        try {
            return $this->apply($pending, $this->hub->signRequest($pending->hub_request_id));
        } catch (HubNotFoundException) {
            $pending->update(['status' => 'failed', 'reason' => 'unknown_request']);
        } catch (HubException) {
            // Still pending as far as we know.
        }

        return $pending;
    }

    /**
     * Record a hub answer (a poll, or the `sign_request.completed` webhook).
     *
     * Only an open request moves: a row already finished here, or blocked by
     * a revocation, keeps its status whatever arrives later.
     *
     * @param  array<string, mixed>  $data  {id, status, cms?, refusal_reason?, certificate_fingerprint?, signed_at?}
     * @param  bool  $fetchCms  ask the hub for the CMS when $data says signed without it
     */
    public function apply(HubPendingSign $pending, array $data, bool $fetchCms = true): HubPendingSign
    {
        $status = $data['status'] ?? null;

        if (! in_array($status, self::HUB_STATUSES, true) || ! in_array($pending->status, self::OPEN_STATUSES, true)) {
            return $pending;
        }

        // `signed` without the CMS (a webhook may leave it out): ask for it.
        if ($status === 'signed' && empty($data['cms'])) {
            if ($fetchCms && $pending->hub_request_id !== null) {
                try {
                    return $this->apply($pending, $this->hub->signRequest($pending->hub_request_id), fetchCms: false);
                } catch (HubException) {
                    // Keep waiting; the next poll asks again.
                }
            }

            return $pending;
        }

        $payload = $pending->payload ?? [];

        foreach (['certificate_fingerprint', 'signed_at'] as $key) {
            if (! empty($data[$key])) {
                $payload[$key] = $data[$key];
            }
        }

        $pending->update([
            'status'  => $status,
            'cms'     => $status === 'signed' ? (string) $data['cms'] : $pending->cms,
            'reason'  => isset($data['refusal_reason']) ? Str::limit((string) $data['refusal_reason'], 60, '') : $pending->reason,
            'payload' => $payload,
        ]);

        if (in_array($status, ['declined', 'refused'], true)) {
            SignatureAudit::record(
                $status === 'declined' ? SignatureAudit::HUB_SIGN_DECLINED : SignatureAudit::HUB_SIGN_REFUSED,
                [
                    'subject_user_id'      => $pending->user_id,
                    'signature_request_id' => $pending->signature_request_id,
                    'context'              => ['hub_request' => $pending->hub_request_id, 'reason' => $pending->reason],
                ],
            );
        }

        return $pending;
    }

    // -------------------------------------------------------------------------
    // Step 3: put the signature on the document
    // -------------------------------------------------------------------------

    /**
     * Inject the hub's CMS and finish the signature row, as
     * SignatureManager::embedAndFinalize() does with a local certificate.
     *
     * @throws HubSigningException when nothing signed is waiting for this row
     */
    public function finalize(Signature $signature): void
    {
        $pending = HubPendingSign::query()
            ->where('user_id', $signature->user_id)
            ->where('source_hash', $signature->document_hash)
            ->where('status', 'signed')
            ->latest('id')
            ->first();

        $cms = $pending?->cms !== null ? base64_decode((string) $pending->cms, true) : false;

        if ($pending === null || $cms === false || $cms === '') {
            throw new HubSigningException(
                'UPLB Signature has not signed this document yet. Sign it again.',
                'not_signed',
            );
        }

        $output = $this->deferred()->inject((string) $pending->prepared_path, $cms);

        $path = app(PdfSignerService::class)->keepSignedVersion($signature, $output);

        $disk = Storage::disk(config('signature.storage_disk'));

        if ($pending->prepared_path && ! in_array($pending->prepared_path, [$output, $path], true)) {
            $disk->delete($pending->prepared_path);
        }

        $payload = $pending->payload ?? [];

        $signature->update([
            'signed_document_path'    => $path,
            'signed_document_hash'    => $this->integrity->hash($path),
            'status'                  => 'signed',
            'signed_at'               => isset($payload['signed_at']) ? Carbon::parse($payload['signed_at']) : now(),
            'certificate_fingerprint' => $this->fingerprint($payload['certificate_fingerprint'] ?? null),
        ]);

        $pending->update([
            'status'  => 'done',
            'payload' => $payload + ['signature_id' => $signature->id],
        ]);

        SignatureAudit::record(SignatureAudit::HUB_SIGNED, [
            'subject_user_id'      => $signature->user_id,
            'signing_session_id'   => $signature->signing_session_id,
            'signature_request_id' => $pending->signature_request_id,
            'signature_id'         => $signature->id,
            'context'              => ['hub_request' => $pending->hub_request_id, 'document_hash' => $pending->document_hash],
        ]);

        event(new DocumentSigned($signature));
    }

    // -------------------------------------------------------------------------
    // The approval overlay's poll
    // -------------------------------------------------------------------------

    /**
     * What `GET signature/agent-web/jobs/{uuid}` answers in client mode, in
     * the shape agentApproval.js polls for: `completed` once the hub signed,
     * `rejected` when declined or refused, `expired`, else `pending`.
     *
     * @return array{status: string, reason: ?string, device: null}|null
     */
    public function webStatus(int $userId, string $uuid): ?array
    {
        $pending = HubPendingSign::query()
            ->where('user_id', $userId)
            ->where(fn ($q) => $q->where('agent_job_uuid', $uuid)->orWhere('hub_request_id', $uuid))
            ->latest('id')
            ->first();

        if ($pending === null) {
            return null;
        }

        if (in_array($pending->status, ['pending', 'approved'], true)) {
            $pending = $this->refresh($pending);
        }

        return [
            'status' => match ($pending->status) {
                'signed', 'done'     => 'completed',
                'declined'           => 'rejected',
                'refused', 'failed'  => 'rejected',
                'expired'            => 'expired',
                default              => 'pending',
            },
            'reason' => $pending->status === 'declined' ? 'declined' : $pending->reason,
            'device' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Stamp the mirror into the document with an empty signature, and record
     * the request. Nothing is sent yet.
     *
     * @param  array<string, mixed>  $context
     */
    protected function prepare(Signature $mirror, int $userId, array $context, string $sourceHash, ?int $requestId): HubPendingSign
    {
        try {
            $mirror = $this->sync->verified($mirror);
        } catch (MirrorIntegrityException|HubException $e) {
            throw new HubSigningException($e->getMessage(), 'no_mirror', $e);
        }

        $prepared = $this->deferred()->prepare(
            pdfPath: $context['source_path'],
            imagePath: (string) $mirror->image_path,
            position: $context['position'],
            reason: 'Signed via '.config('app.name'),
            caption: app(SignatureCaption::class)->linesFor((new Signature)->forceFill(['user_id' => $userId])),
            extraPositions: $context['extra_positions'],
            imageDisk: MirrorStorage::diskNameFor($mirror),
        );

        return HubPendingSign::create([
            'user_id'              => $userId,
            'signature_request_id' => $requestId,
            'idempotency_key'      => (string) Str::uuid(),
            'document_hash'        => $prepared->digest,
            'source_hash'          => $sourceHash,
            'specimen_hash'        => (string) $mirror->hub_image_hash,
            'prepared_path'        => $prepared->path,
            'payload'              => $context + ['mirror_id' => $mirror->id],
            'status'               => 'unsent',
            'attempts'             => 0,
        ]);
    }

    /**
     * After a 409: pull the hub's current image and stamp the same document
     * again with it, as a new request.
     */
    protected function restamp(HubPendingSign $stale): HubPendingSign
    {
        try {
            $mirror = $this->sync->pull((int) $stale->user_id);
        } catch (HubUnavailableException $e) {
            throw HubSigningException::unavailable($e);
        } catch (HubException|MirrorIntegrityException $e) {
            throw new HubSigningException($e->getMessage(), 'no_mirror', $e);
        }

        if ($mirror === null) {
            throw new HubSigningException(
                'You no longer have a signature at UPLB Signature. Add one there, then try again.',
                'no_mirror',
            );
        }

        if ($stale->prepared_path) {
            Storage::disk(config('signature.storage_disk'))->delete($stale->prepared_path);
        }

        $context = $stale->payload ?? [];

        unset($context['approval_link'], $context['expires_at'], $context['mirror_id']);

        return $this->prepare($mirror, (int) $stale->user_id, $context, (string) $stale->source_hash, $stale->signature_request_id);
    }

    /** The session slot behind a chain, so retries find their own request. */
    protected function requestFor(?array $chain): ?SignatureRequest
    {
        if (empty($chain['signing_session_id']) || empty($chain['slot_key'])) {
            return null;
        }

        return SignatureRequest::query()
            ->where('signing_session_id', $chain['signing_session_id'])
            ->where('slot_key', $chain['slot_key'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{page: int, x: float, y: float, width: float, height: float}
     */
    protected function geometry(array $position): array
    {
        return [
            'page'   => (int) ($position['page'] ?? 1),
            'x'      => (float) ($position['x'] ?? 0),
            'y'      => (float) ($position['y'] ?? 0),
            'width'  => (float) ($position['width'] ?? 0),
            'height' => (float) ($position['height'] ?? 0),
        ];
    }

    protected function titleOf(Signable $signable): string
    {
        $title = method_exists($signable, 'getSignableTitle') ? (string) $signable->getSignableTitle() : '';

        return Str::limit($title !== '' ? $title : class_basename($signable).' #'.$signable->getSignableId(), 190);
    }

    /** SHA-256 hex, however the hub spells it, to fit the 64-char column. */
    protected function fingerprint(?string $fingerprint): ?string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }

        return substr(strtolower(str_replace([':', ' '], '', $fingerprint)), 0, 64);
    }

    protected function refusalMessage(?string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'not_verified'        => 'UPLB Signature has not verified your identity yet, so it cannot sign for you.',
            'no_signature'        => 'You have no signature at UPLB Signature. Add one there, then try again.',
            'certificate_revoked' => 'Your signing certificate was revoked at UPLB Signature.',
            'separated'           => 'UPLB Signature no longer signs for this person.',
            'unknown_person'      => 'UPLB Signature does not know this account. Sign in with UPLB Signature again.',
            'not_linked'          => 'This app is not allowed to use your signature yet. Sign in with UPLB Signature again.',
            'signature_revoked', 'revoked' => 'Your signature was revoked at UPLB Signature.',
            'expired'             => 'The request timed out before it was approved. Nothing was signed.',
            default               => $fallback ?: 'UPLB Signature did not sign this document.',
        };
    }

    protected function deferred(): DeferredPdfSigner
    {
        return app(DeferredPdfSigner::class);
    }
}
