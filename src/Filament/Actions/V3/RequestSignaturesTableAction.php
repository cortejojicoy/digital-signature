<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Tables\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\RequestsSignatures;

/**
 * Filament v3 table-row placement.
 */
class RequestSignaturesTableAction extends Action
{
    use RequestsSignatures;
}
