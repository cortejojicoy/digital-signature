<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\RoutesForSignatures;

/**
 * Filament v4 / v5 page action. See RoutesForSignatures.
 */
class RouteForSignaturesAction extends Action
{
    use RoutesForSignatures;
}
