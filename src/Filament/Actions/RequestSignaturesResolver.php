<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class RequestSignaturesResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction::class;

    protected const V3 = V3\RequestSignaturesAction::class;

    protected const V4 = V4\RequestSignaturesAction::class;
}
