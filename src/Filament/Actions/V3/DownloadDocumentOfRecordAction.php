<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\DownloadsDocumentOfRecord;

/**
 * Filament v3 page action. See DownloadsDocumentOfRecord.
 */
class DownloadDocumentOfRecordAction extends Action
{
    use DownloadsDocumentOfRecord;
}
