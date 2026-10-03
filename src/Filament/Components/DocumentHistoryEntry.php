<?php

namespace Kukux\DigitalSignature\Filament\Components;

use Filament\Infolists\Components\Entry;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory;

/**
 * Every version of the record's routed document, as an infolist entry:
 *
 *   DocumentHistoryEntry::make()
 *
 * No version split, for the reason given on SignatoryPanel: Entry is the
 * base class on v3, v4 and v5 alike.
 */
class DocumentHistoryEntry extends Entry
{
    protected string $view = 'signature::components.document-history-entry';

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'document_history');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Signing history');
    }

    public function getHistory(): ?DocumentHistory
    {
        $record = $this->getRecord();

        return $record instanceof Model ? DocumentHistory::for($record) : null;
    }
}
