<?php

namespace Kukux\DigitalSignature\Filament\Actions\V4;

use Filament\Actions\Action;
use Kukux\DigitalSignature\Filament\Actions\Concerns\ViewsDocumentHistory;

/**
 * Filament v4 / v5 page action. See ViewsDocumentHistory.
 */
class ViewDocumentHistoryAction extends Action
{
    use ViewsDocumentHistory;
}
