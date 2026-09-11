<?php

namespace Kukux\DigitalSignature\Filament\Pages\V3;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsSignatureInbox;

/**
 * Filament v3: Page::$view is a STATIC property.
 */
class SignatureInbox extends Page
{
    use IsSignatureInbox;

    protected static string $view = 'signature::filament.pages.signature-inbox';

    protected static ?string $slug = 'signature-inbox';

    protected static ?string $title = 'Awaiting my signature';
}
