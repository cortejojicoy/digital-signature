<?php

namespace Kukux\DigitalSignature\Filament\Actions\V3;

use Filament\Tables\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\SignsDocuments;

/**
 * Filament v3 implementation.
 *
 * On v3, Filament\Actions\Action is a *page/header* action and cannot be
 * passed to a table's ->actions([...]). Table row actions must extend
 * Filament\Tables\Actions\Action, which is what this class does.
 *
 * All behaviour lives in the SignsDocuments trait — see V4\SignDocumentAction
 * for the counterpart.
 */
class SignDocumentAction extends Action
{
    use SignsDocuments;
}
