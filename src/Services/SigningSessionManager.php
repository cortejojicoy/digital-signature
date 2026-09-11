<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\ConfiguresSigningSession;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Contracts\SupportsIncrementalSigning;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Events\SignatureDeclined;
use Kukux\DigitalSignature\Events\SignatureRequested;
use Kukux\DigitalSignature\Events\SigningSessionCompleted;
use Kukux\DigitalSignature\Events\SigningSessionOpened;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\IncrementalSigningUnsupportedException;
use Kukux\DigitalSignature\Exceptions\OutOfSequenceException;
use Kukux\DigitalSignature\Exceptions\SignatoryNotReadyException;
use Kukux\DigitalSignature\Exceptions\SigningSessionClosedException;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * Drives a document through several signatories.
 *
 * Two invariants do the real work here:
 *
 *  1. **The document is rendered once.** `open()` freezes the PDF into the
 *     session. Every later signature builds on the session's running
 *     document, never on a fresh render — otherwise the second signatory's
 *     PDF would not contain the first signatory's stamp.
 *
 *  2. **Each signature is chained to the last.** Signature N's
 *     `document_hash` is signature N-1's `signed_document_hash`, and
 *     `parent_signature_id` records the link. That chain is verifiable from
 *     the database independently of what the PDF itself can carry.
 *
 * See docs/signatory-routing.md, "Signing modes", for what `progressive`
 * and `incremental` each guarantee cryptographically.
 */
class SigningSessionManager
{
    public function __construct(
        protected PdfTemplateRegistry $registry,
        protected SignatoryRouter $router,
        protected SignatureManager $signatures,
        protected DocumentIntegrity $integrity,
    ) {
    }

    // -------------------------------------------------------------------------
    // Opening
    // -------------------------------------------------------------------------

    /**
     * Freeze the record's PDF and create one request per routed slot.
     *
     * Idempotent: an already-open session for the same record and template is
     * returned as-is (with assignments refreshed) rather than duplicated.
     */
    public function open(Model $record, ?string $templateKey = null, ?int $actorId = null): SigningSession
    {
        $templateKey ??= $this->templateKeyFor($record);
        $template = $this->registry->get($templateKey);

        $existing = SigningSession::query()
            ->forSignable($record)
            ->where('template_key', $templateKey)
            ->open()
            ->latest('id')
            ->first();

        if ($existing !== null) {
            $this->refreshAssignments($existing);

            return $existing;
        }

        $basePath = $this->freezeDocument($record, $template);

        return DB::transaction(function () use ($record, $template, $templateKey, $basePath, $actorId) {
            $session = SigningSession::create([
                'uuid'                  => (string) Str::uuid(),
                'signable_type'         => $record->getMorphClass(),
                'signable_id'           => $record->getKey(),
                'template_key'          => $templateKey,
                'base_document_path'    => $basePath,
                'base_document_hash'    => $this->integrity->hash($basePath),
                'current_document_path' => null,
                'current_document_hash' => null,
                'status'                => SigningSession::STATUS_OPEN,
                'sequence_mode'         => $this->sequenceModeFor($template),
                'signing_mode'          => config('signature.multi_signature.mode', 'progressive'),
                'opened_by'             => $actorId ?? auth()->id(),
                'expires_at'            => $this->defaultExpiry(),
            ]);

            $this->createRequests($session, $record);

            SignatureAudit::record(SignatureAudit::SESSION_OPENED, [
                'signing_session_id' => $session->id,
                'context'            => [
                    'template' => $templateKey,
                    'signable' => $record->getMorphClass().'#'.$record->getKey(),
                ],
            ]);

            event(new SigningSessionOpened($session));

            return $session->fresh(['requests']);
        });
    }

    /**
     * Re-run routing against the record and fill in any request whose
     * signatory was unassigned when the session opened.
     *
     * Requests that already reached a decision are never touched — a signature
     * that has happened cannot be reassigned by editing the record.
     *
     * @return int Number of requests updated.
     */
    public function refreshAssignments(SigningSession $session): int
    {
        $record = $session->signable;

        if ($record === null) {
            return 0;
        }

        $this->router->forgetResolved($record);
        $routes = $this->router->routeFor($record, $session->template_key);

        $updated = 0;

        foreach ($session->requests as $request) {
            if ($request->state->isTerminal()) {
                continue;
            }

            $route = $routes[$request->slot_key] ?? null;

            if ($route === null) {
                continue;
            }

            $newUserId = $route->user?->getKey();

            if ((string) $request->user_id === (string) $newUserId) {
                continue;
            }

            $request->update([
                'user_id' => $newUserId,
                'state'   => $this->initialStateFor($route->state),
            ]);

            $updated++;

            if ($newUserId !== null) {
                event(new SignatureRequested($request->fresh()));
            }
        }

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Signing
    // -------------------------------------------------------------------------

    /**
     * Apply one signatory's signature to the session's running document.
     *
     * @param  int  $actorUserId  The authenticated user performing the signature.
     *                            Must be the request's assigned signatory —
     *                            delegated signing goes through AutoAffixService.
     */
    public function sign(
        SignatureRequest $request,
        int $actorUserId,
        ?Signature $useSignature = null,
        ?string $password = null,
    ): Signature {
        $session = $request->session;

        $this->assertSessionOpen($session);

        if ((int) $request->user_id !== $actorUserId) {
            throw new ForgedSignatureException(
                'This signature request is assigned to a different user.'
            );
        }

        return $this->applySignature($request, $useSignature, $password, delegated: false);
    }

    /**
     * Shared signing body for both the in-person and delegated paths.
     *
     * Kept internal so the ownership rules stay at the entry points: sign()
     * requires the actor to BE the signatory; AutoAffixService requires a
     * usable delegation. Neither check can be bypassed by reaching here,
     * because this method is only callable from inside the package.
     *
     * @internal
     */
    public function applySignature(
        SignatureRequest $request,
        ?Signature $useSignature,
        ?string $password,
        bool $delegated,
    ): Signature {
        $session = $request->session;

        $this->assertSessionOpen($session);
        $this->assertInSequence($request);

        if ($request->state->isTerminal()) {
            throw new SignatoryNotReadyException(sprintf(
                'This slot is already %s.',
                $request->state->value,
            ));
        }

        $record = $session->signable;

        if (! $record instanceof Signable) {
            throw new SignatoryNotReadyException(sprintf(
                'The record behind this session (%s) does not implement %s, so it cannot be signed.',
                $session->signable_type,
                Signable::class,
            ));
        }

        $signature = $useSignature ?? $this->primarySignatureFor((int) $request->user_id);

        if ($signature === null) {
            throw new SignatoryNotReadyException(
                'The assigned signatory has no active registered signature.'
            );
        }

        if ((int) $signature->user_id !== (int) $request->user_id) {
            throw new ForgedSignatureException(
                'The selected signature does not belong to the assigned signatory.'
            );
        }

        $position = $request->position();

        if ($position === null) {
            throw new SignatoryNotReadyException(sprintf(
                'Slot [%s] has no placement. Open the template designer and position it before signing.',
                $request->slot_key,
            ));
        }

        $signingPassword = $password ?? $signature->getCertificatePassword();

        if (! $signingPassword) {
            throw new SignatoryNotReadyException(
                'No certificate password is stored for this signature, and none was supplied.'
            );
        }

        $sourcePath = $session->documentToSign();

        $this->assertModeSupported($session);

        $previous = $session->signatures()->orderByDesc('sequence')->first();

        $chain = [
            'signing_session_id'  => $session->id,
            'slot_key'            => $request->slot_key,
            'sequence'            => $request->sequence,
            'parent_signature_id' => $previous?->id,
        ];

        $documentSignature = $delegated
            ? $this->signatures->storeDelegated(
                source:        $signature,
                signable:      $record,
                position:      $position,
                sourcePdfPath: $sourcePath,
                chain:         $chain,
            )
            : $this->signatures->storeForDocument(
                source:        $signature,
                signerUserId:  (int) $request->user_id,
                signable:      $record,
                position:      $position,
                sourcePdfPath: $sourcePath,
                chain:         $chain,
            );

        $this->signatures->embedAndFinalize($documentSignature, $signingPassword, $sourcePath);

        $documentSignature->refresh();

        return DB::transaction(function () use ($session, $request, $documentSignature) {
            $session->update([
                'current_document_path' => $documentSignature->signed_document_path,
                'current_document_hash' => $documentSignature->signed_document_hash,
            ]);

            $request->update([
                'signature_id' => $documentSignature->id,
                'state'        => RouteState::Signed,
                'responded_at' => now(),
            ]);

            SignatureAudit::record(SignatureAudit::REQUEST_SIGNED, [
                'subject_user_id'      => $request->user_id,
                'signing_session_id'   => $session->id,
                'signature_request_id' => $request->id,
                'signature_id'         => $documentSignature->id,
                'context'              => [
                    'slot'     => $request->slot_key,
                    'role'     => $request->role,
                    'sequence' => $request->sequence,
                ],
            ]);

            $this->completeIfFinished($session->fresh(['requests']));

            return $documentSignature;
        });
    }

    /**
     * Record a signatory's refusal. The session stays open so the host can
     * reassign the role or cancel deliberately.
     */
    public function decline(SignatureRequest $request, int $actorUserId, ?string $reason = null): SignatureRequest
    {
        $this->assertSessionOpen($request->session);

        if ((int) $request->user_id !== $actorUserId) {
            throw new ForgedSignatureException(
                'Only the assigned signatory can decline this request.'
            );
        }

        $request->update([
            'state'           => RouteState::Declined,
            'responded_at'    => now(),
            'declined_reason' => $reason,
        ]);

        SignatureAudit::record(SignatureAudit::REQUEST_DECLINED, [
            'subject_user_id'      => $request->user_id,
            'signing_session_id'   => $request->signing_session_id,
            'signature_request_id' => $request->id,
            'context'              => ['slot' => $request->slot_key, 'reason' => $reason],
        ]);

        event(new SignatureDeclined($request, $reason));

        return $request;
    }

    public function cancel(SigningSession $session, ?string $reason = null): SigningSession
    {
        $session->update([
            'status'       => SigningSession::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        SignatureAudit::record(SignatureAudit::SESSION_CANCELLED, [
            'signing_session_id' => $session->id,
            'context'            => ['reason' => $reason],
        ]);

        return $session;
    }

    /**
     * Mark the session complete once every required slot is signed.
     */
    public function completeIfFinished(SigningSession $session): bool
    {
        if (! $session->isOpen()) {
            return false;
        }

        if ($session->outstandingRequests()->isNotEmpty()) {
            return false;
        }

        $session->update([
            'status'       => SigningSession::STATUS_COMPLETE,
            'completed_at' => now(),
        ]);

        SignatureAudit::record(SignatureAudit::SESSION_COMPLETED, [
            'signing_session_id' => $session->id,
            'context'            => ['document' => $session->current_document_path],
        ]);

        event(new SigningSessionCompleted($session->fresh()));

        return true;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Render the record's PDF and copy it somewhere the session owns, so a
     * later re-render (or a cache eviction) cannot change what is being signed.
     *
     * @return string Disk-relative path.
     */
    protected function freezeDocument(Model $record, PdfTemplate $template): string
    {
        $rendered = $template->renderFor($record);

        $disk = Storage::disk(config('signature.storage_disk'));

        $relative = sprintf(
            'signing-sessions/%s/%s/base-%s.pdf',
            $template->key(),
            $record->getKey(),
            now()->format('YmdHis'),
        );

        $bytes = is_file($rendered)
            ? file_get_contents($rendered)
            : $disk->get(\Kukux\DigitalSignature\Support\DiskPath::relative($rendered));

        if ($bytes === false || $bytes === null) {
            throw new \RuntimeException(sprintf(
                'Could not read the rendered PDF for [%s] at [%s].',
                $template->key(),
                $rendered,
            ));
        }

        $disk->put($relative, $bytes);

        return $relative;
    }

    protected function createRequests(SigningSession $session, Model $record): void
    {
        $routes = $this->router->routeFor($record, $session->template_key);

        $sequence = 0;

        foreach ($routes as $route) {
            $sequence++;

            $position = $route->position;

            $request = SignatureRequest::create([
                'uuid'               => (string) Str::uuid(),
                'signing_session_id' => $session->id,
                'slot_key'           => $route->key(),
                'role'               => $route->role(),
                'user_id'            => $route->user?->getKey(),
                'sequence'           => $route->slot->order ?? $sequence,
                'required'           => $route->isRequired(),
                'state'              => $this->initialStateFor($route->state),
                'page'               => $position['page']   ?? null,
                'x'                  => $position['x']      ?? null,
                'y'                  => $position['y']      ?? null,
                'width'              => $position['width']  ?? null,
                'height'             => $position['height'] ?? null,
                'requested_at'       => now(),
            ]);

            SignatureAudit::record(SignatureAudit::REQUEST_CREATED, [
                'subject_user_id'      => $request->user_id,
                'signing_session_id'   => $session->id,
                'signature_request_id' => $request->id,
                'context'              => ['slot' => $request->slot_key, 'role' => $request->role],
            ]);

            if ($request->user_id !== null) {
                event(new SignatureRequested($request));
            }
        }
    }

    /**
     * Routing reports `Blocked` and `Ready` as views of the current moment;
     * persisted request state should record only what is intrinsic to the
     * slot, letting sequencing be recomputed as the session progresses.
     */
    protected function initialStateFor(RouteState $routed): RouteState
    {
        return match ($routed) {
            RouteState::Unassigned           => RouteState::Unassigned,
            RouteState::AwaitingRegistration => RouteState::AwaitingRegistration,
            default                          => RouteState::AwaitingConsent,
        };
    }

    protected function assertSessionOpen(?SigningSession $session): void
    {
        if ($session === null) {
            throw new SigningSessionClosedException('This request has no signing session.');
        }

        if ($session->hasExpired() && $session->isOpen()) {
            $session->update(['status' => SigningSession::STATUS_EXPIRED]);
        }

        if (! $session->isOpen()) {
            throw new SigningSessionClosedException(sprintf(
                'This signing session is %s and no longer accepts signatures.',
                $session->status,
            ));
        }
    }

    /**
     * In a sequential session, refuse a signature while an earlier required
     * slot is still outstanding.
     */
    protected function assertInSequence(SignatureRequest $request): void
    {
        $session = $request->session;

        if (! $session->isSequential()) {
            return;
        }

        $blocking = $session->requests()
            ->where('required', true)
            ->where('sequence', '<', $request->sequence)
            ->where('state', '!=', RouteState::Signed->value)
            ->orderBy('sequence')
            ->first();

        if ($blocking === null) {
            return;
        }

        throw new OutOfSequenceException(sprintf(
            'This document is signed in order — "%s" must be signed before "%s".',
            $blocking->role,
            $request->role,
        ));
    }

    /**
     * Fail loudly and early when the host asked for incremental signing but
     * the configured driver cannot deliver it, rather than silently producing
     * a document whose earlier signatures have been invalidated.
     */
    protected function assertModeSupported(SigningSession $session): void
    {
        if ($session->signing_mode !== 'incremental') {
            return;
        }

        $driver = app(PdfSignerDriver::class);

        if ($driver instanceof SupportsIncrementalSigning) {
            return;
        }

        throw new IncrementalSigningUnsupportedException(sprintf(
            'signature.multi_signature.mode is set to "incremental", but the [%s] driver cannot append '
            .'a signature without rewriting the document — doing so would invalidate every earlier '
            .'signature. Use the "progressive" mode, or configure a driver implementing %s.',
            $driver::class,
            SupportsIncrementalSigning::class,
        ));
    }

    protected function primarySignatureFor(int $userId): ?Signature
    {
        return Signature::primaryActiveFor($userId)->latest('id')->first();
    }

    protected function templateKeyFor(Model $record): string
    {
        if (method_exists($record, 'signatureTemplateKey')) {
            return $record->signatureTemplateKey();
        }

        throw new \InvalidArgumentException(sprintf(
            'No template key given and [%s] does not expose signatureTemplateKey(). '
            .'Use the HasSignatories or HasPdfTemplate trait, or pass the key explicitly.',
            $record::class,
        ));
    }

    protected function sequenceModeFor(PdfTemplate $template): string
    {
        if ($template instanceof ConfiguresSigningSession) {
            return $template->sequenceMode();
        }

        return config('signature.sessions.sequence_mode', SigningSession::MODE_SEQUENTIAL);
    }

    protected function defaultExpiry(): ?\DateTimeInterface
    {
        $days = config('signature.sessions.expires_after_days');

        return $days ? now()->addDays((int) $days) : null;
    }
}
