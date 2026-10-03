<?php

namespace Kukux\DigitalSignature\Filament\Actions;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class RouteForSignaturesResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction::class;

    protected const V3 = V3\RouteForSignaturesAction::class;

    protected const V4 = V4\RouteForSignaturesAction::class;
}
