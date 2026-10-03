<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\DownloadsDocumentOfRecord;

/**
 * Filament v4 / v5 page action. See DownloadsDocumentOfRecord.
 */
class DownloadDocumentOfRecordAction extends Action
{
    use DownloadsDocumentOfRecord;
}
