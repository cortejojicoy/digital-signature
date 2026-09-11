<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\SignsDocuments;

/**
 * Filament v3 page/header placement of the sign action.
 *
 * v3 draws a hard line the later majors removed: Filament\Actions\Action is
 * for pages and headers, Filament\Tables\Actions\Action is for table rows,
 * and they are not interchangeable. So v3 needs two classes where v4/v5 need
 * one — SignDocumentAction for table rows, this for headers.
 */
class SignDocumentHeaderAction extends Action
{
    use SignsDocuments;
}
