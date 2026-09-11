<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * Table-row placement of RequestSignaturesAction. On v4/v5 this is the same
 * class as the header variant; on v3 it is the Tables\Actions\Action subclass.
 */
class RequestSignaturesTableResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\RequestSignaturesTableAction::class;

    protected const V3 = V3\RequestSignaturesTableAction::class;

    protected const V4 = V4\RequestSignaturesAction::class;
}
