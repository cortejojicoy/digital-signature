<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * Aliases the canonical SignDocumentAction to the v3 or v4/v5 base class.
 * See ComponentResolver for the pattern.
 */
class ActionResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\SignDocumentAction::class;

    protected const V3 = V3\SignDocumentAction::class;

    protected const V4 = V4\SignDocumentAction::class;
}
