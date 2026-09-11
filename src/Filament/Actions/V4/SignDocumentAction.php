<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\SignsDocuments;

/**
 * Filament v4 / v5 implementation.
 *
 * v4 unified page, table and infolist actions under Filament\Actions\Action,
 * so one class covers every placement. All behaviour lives in the
 * SignsDocuments trait — see V3\SignDocumentAction for the legacy base.
 */
class SignDocumentAction extends Action
{
    use SignsDocuments;
}
