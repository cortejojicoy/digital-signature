<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class DownloadDocumentOfRecordResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\DownloadDocumentOfRecordAction::class;

    protected const V3 = V3\DownloadDocumentOfRecordAction::class;

    protected const V4 = V4\DownloadDocumentOfRecordAction::class;
}
