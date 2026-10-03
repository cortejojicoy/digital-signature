<?php

namespace Kukux\DigitalSignature\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kukux\DigitalSignature\Contracts\DocumentOfRecordGate;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecord;
use Kukux\DigitalSignature\Exceptions\DocumentRetainedException;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;

/**
 * Host-model convenience layer over SignatoryRouter and SigningSessionManager.
 *
 * A model that already uses HasPdfTemplate gets the template key for free;
 * anything else can set $signaturePdfTemplate or override
 * signatureTemplateKey().
 *
 *   class AccomplishmentReport extends Model implements Signable
 *   {
 *       use HasPdfTemplate, HasSignatories;
 *
 *       protected string $signaturePdfTemplate = 'accomplishment-report';
 *
 *       public function preparedBy(): BelongsTo { … }
 *       public function attestedBy(): BelongsTo { … }
 *       public function notedBy(): BelongsTo    { … }
 *   }
 *
 * Then: $report->signatoryRoutes(), ->signatureBlockers(), ->openSigningSession().
 */
trait HasSignatories
{
    /**
     * A record whose document has been routed keeps it: its signed versions
     * are a history its signatories are entitled to (see DocumentOfRecordGate).
     * Soft deletes are left alone, since nothing is lost.
     */
    public static function bootHasSignatories(): void
    {
        static::deleting(function (Model $record): void {
            if (method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
                return;
            }

            if (! app(DocumentOfRecordGate::class)->canDelete($record)) {
                throw new DocumentRetainedException(sprintf(
                    '%s #%s has been routed for signatures, so its signed versions are kept. '
                    .'Bind a DocumentOfRecordGate whose canDelete() allows this if it must go.',
                    class_basename($record),
                    $record->getKey(),
                ));
            }
        });
    }

    /**
     * The routed document: the stored PDF its signatories signed, and where
     * it stands. Null until it has been routed; a draft is rendered live.
     */
    public function documentOfRecord(): ?DocumentOfRecord
    {
        return DocumentOfRecord::for($this);
    }

    /** Every version of the routed document, oldest first. */
    public function documentHistory(): DocumentHistory
    {
        return DocumentHistory::for($this);
    }

    /**
     * Template key this record's signature layout comes from.
     *
     * Deliberately NOT named signatureTemplateKey(): HasPdfTemplate already
     * defines that, and two traits declaring the same method is a PHP fatal
     * that would force every host model composing both to write an
     * `insteadof` clause. So HasPdfTemplate owns the public name and this
     * defers to it, falling back to the property for models that use
     * HasSignatories on its own.
     */
    protected function resolveSignatureTemplateKey(): string
    {
        if (method_exists($this, 'signatureTemplateKey')) {
            return $this->signatureTemplateKey();
        }

        if (property_exists($this, 'signaturePdfTemplate') && $this->signaturePdfTemplate !== '') {
            return $this->signaturePdfTemplate;
        }

        throw new \LogicException(sprintf(
            '[%s] uses HasSignatories but declares no template. Add the HasPdfTemplate '
            .'trait, set protected string $signaturePdfTemplate = \'…\';, or define '
            .'signatureTemplateKey() yourself.',
            static::class,
        ));
    }

    /**
     * Every slot, joined to its assigned person, their signature and state.
     *
     * @return array<string, SignatoryRoute>
     */
    public function signatoryRoutes(): array
    {
        return app(SignatoryRouter::class)->routeFor($this, $this->resolveSignatureTemplateKey());
    }

    /**
     * Slot key => why it isn't done yet. Empty when nothing blocks completion.
     *
     * @return array<string, string>
     */
    public function signatureBlockers(): array
    {
        return app(SignatoryRouter::class)->blockers($this, $this->resolveSignatureTemplateKey());
    }

    /**
     * True when every required role is filled by someone who has a registered
     * signature — i.e. a session opened now would not immediately stall.
     */
    public function isReadyForSignatures(): bool
    {
        return app(SignatoryRouter::class)->isRoutable($this, $this->resolveSignatureTemplateKey());
    }

    /**
     * All signing sessions ever opened for this record, newest first.
     */
    public function signingSessions(): HasMany
    {
        return $this->hasMany(SigningSession::class, 'signable_id')
            ->where('signable_type', $this->getMorphClass())
            ->latest('id');
    }

    /**
     * The session currently in flight, if any.
     */
    public function currentSigningSession(): ?SigningSession
    {
        return SigningSession::query()
            ->forSignable($this)
            ->open()
            ->latest('id')
            ->first();
    }

    public function latestSigningSession(): ?SigningSession
    {
        return SigningSession::query()
            ->forSignable($this)
            ->latest('id')
            ->first();
    }

    /**
     * Freeze this record's PDF and create a request per routed slot.
     * Returns the existing open session when one is already in flight.
     */
    public function openSigningSession(): SigningSession
    {
        return app(SigningSessionManager::class)->open($this, $this->resolveSignatureTemplateKey());
    }

    /**
     * Path to the finished, fully-signed PDF — null until the session completes.
     */
    public function signedDocumentPath(): ?string
    {
        $session = SigningSession::query()
            ->forSignable($this)
            ->where('status', SigningSession::STATUS_COMPLETE)
            ->latest('id')
            ->first();

        return $session?->current_document_path;
    }

    public function isFullySigned(): bool
    {
        return $this->signedDocumentPath() !== null;
    }

    /**
     * The open request awaiting the given user, if this record is waiting on them.
     */
    public function pendingSignatureRequestFor(int $userId): ?SignatureRequest
    {
        $session = $this->currentSigningSession();

        if ($session === null) {
            return null;
        }

        return $session->requests()
            ->where('user_id', $userId)
            ->whereIn('state', [
                RouteState::AwaitingConsent->value,
                RouteState::Ready->value,
            ])
            ->first();
    }
}
