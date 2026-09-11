<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\RequestsSignatures;

/**
 * Filament v4 / v5 — one class covers header, table and infolist placements.
 */
class RequestSignaturesAction extends Action
{
    use RequestsSignatures;
}
