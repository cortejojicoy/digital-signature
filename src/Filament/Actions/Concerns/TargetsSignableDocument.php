<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignableDocument;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecord;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecordResolver;
use Kukux\DigitalSignature\Documents\TemplateDocument;
use Kukux\DigitalSignature\Services\DocumentRegistry;

/**
 * Which document an action is about, shared by every document action.
 *
 * On a record page there is nothing to configure: the record is the subject
 * and its own template defines it. On a page that generates a document from
 * something else, say which document and what it's built from:
 *
 *   ->document('accomplishment-report')
 *   ->subject(fn () => $this->currentPersonnel())
 *   ->context(fn () => $this->dateRange)
 */
trait TargetsSignableDocument
{
    protected string|SignableDocument|null $documentKey = null;

    protected mixed $documentSubject = null;

    protected array|Closure $documentContext = [];

    /** A registered document key, a SignableDocument class-string, or an instance. */
    public function document(string|SignableDocument $document): static
    {
        $this->documentKey = $document;

        return $this;
    }

    /** What the document is built from. Defaults to the action's record. */
    public function subject(mixed $subject): static
    {
        $this->documentSubject = $subject;

        return $this;
    }

    /** @param  array<string, mixed>|Closure  $context  e.g. a period */
    public function context(array|Closure $context): static
    {
        $this->documentContext = $context;

        return $this;
    }

    public function getDocumentSubject(): mixed
    {
        return $this->documentSubject !== null
            ? $this->evaluate($this->documentSubject)
            : $this->getRecord();
    }

    /** @return array<string, mixed> */
    public function getDocumentContext(): array
    {
        return (array) $this->evaluate($this->documentContext);
    }

    public function getDocumentDefinition(): SignableDocument
    {
        if ($this->documentKey !== null) {
            return app(DocumentRegistry::class)->resolve($this->documentKey);
        }

        $subject = $this->getDocumentSubject();

        if ($subject instanceof Model && method_exists($subject, 'signatureTemplateKey')) {
            return new TemplateDocument($subject->signatureTemplateKey());
        }

        throw new \LogicException(sprintf(
            '%s needs ->document(…), or a record that uses HasPdfTemplate.',
            static::class,
        ));
    }

    /** The routed document behind this action, or null if it was never routed. */
    public function getDocumentOfRecord(): ?DocumentOfRecord
    {
        try {
            return app(DocumentOfRecordResolver::class)->locate(
                $this->getDocumentDefinition(),
                $this->getDocumentSubject(),
                $this->getDocumentContext(),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
