<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\RoutesForSignatures;

/**
 * Filament v3 page action. See RoutesForSignatures.
 */
class RouteForSignaturesAction extends Action
{
    use RoutesForSignatures;
}
