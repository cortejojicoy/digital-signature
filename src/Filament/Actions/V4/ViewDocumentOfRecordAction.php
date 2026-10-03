<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\ViewsDocumentOfRecord;

/**
 * Filament v4 / v5 page action. See ViewsDocumentOfRecord.
 */
class ViewDocumentOfRecordAction extends Action
{
    use ViewsDocumentOfRecord;
}
