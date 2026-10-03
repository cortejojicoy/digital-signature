<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

/**
 * Opens a routed document's current version in a new tab: the stored PDF
 * its signatories signed. Hidden until the document has been routed; a draft
 * has no document of record.
 */
trait ViewsDocumentOfRecord
{
    use TargetsSignableDocument;

    public static function getDefaultName(): ?string
    {
        return 'view_document_of_record';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('View signed document');
        $this->icon('heroicon-o-document-check');
        $this->color('gray');
        $this->url(fn (): ?string => $this->getDocumentOfRecord()?->url());
        $this->openUrlInNewTab();
        $this->visible(fn (): bool => $this->getDocumentOfRecord() !== null);
    }
}
