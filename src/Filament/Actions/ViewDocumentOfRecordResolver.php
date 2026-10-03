<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class ViewDocumentOfRecordResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\ViewDocumentOfRecordAction::class;

    protected const V3 = V3\ViewDocumentOfRecordAction::class;

    protected const V4 = V4\ViewDocumentOfRecordAction::class;
}
