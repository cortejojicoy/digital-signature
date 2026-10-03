<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class ViewDocumentHistoryResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\ViewDocumentHistoryAction::class;

    protected const V3 = V3\ViewDocumentHistoryAction::class;

    protected const V4 = V4\ViewDocumentHistoryAction::class;
}
