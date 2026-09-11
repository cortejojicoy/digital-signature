<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\RequestsSignatures;

/**
 * Filament v3 header/page placement. For a table row on v3 use
 * V3\RequestSignaturesTableAction instead — see SignDocumentHeaderAction
 * for why v3 needs the two separate bases.
 */
class RequestSignaturesAction extends Action
{
    use RequestsSignatures;
}
