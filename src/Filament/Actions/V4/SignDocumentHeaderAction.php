<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\SignsDocuments;

/**
 * Filament v4 / v5 header placement.
 *
 * Identical to V4\SignDocumentAction — v4 unified the action hierarchy, so
 * the header/table distinction that v3 enforces no longer exists. The class
 * is kept so host code can use one name across all three majors.
 */
class SignDocumentHeaderAction extends Action
{
    use SignsDocuments;
}
