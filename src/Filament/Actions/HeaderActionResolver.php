<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * Aliases SignDocumentHeaderAction — the page/header counterpart to
 * SignDocumentAction. See V3\SignDocumentHeaderAction for why v3 needs both.
 */
class HeaderActionResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\SignDocumentHeaderAction::class;

    protected const V3 = V3\SignDocumentHeaderAction::class;

    protected const V4 = V4\SignDocumentHeaderAction::class;
}
