<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Illuminate\Contracts\View\View;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory;

/**
 * A modal listing every version of a routed document: who produced it, when,
 * its hash, whether the file still matches, and a link to open it.
 */
trait ViewsDocumentHistory
{
    use TargetsSignableDocument;

    public static function getDefaultName(): ?string
    {
        return 'view_document_history';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('History');
        $this->icon('heroicon-o-clock');
        $this->color('gray');
        $this->modalHeading('Signing history');
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel('Close');
        $this->visible(fn (): bool => $this->getDocumentOfRecord() !== null);
        $this->modalContent(fn (): View => view('signature::components.document-history', [
            'history' => ($document = $this->getDocumentOfRecord()) === null
                ? null
                : DocumentHistory::for($document->record),
        ]));
    }
}
