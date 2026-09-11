<?php

namespace Kukux\DigitalSignature\Filament\Pages\V4;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsSignatureInbox;

/**
 * Filament v4 / v5: Page::$view is an INSTANCE property.
 */
class SignatureInbox extends Page
{
    use IsSignatureInbox;

    protected string $view = 'signature::filament.pages.signature-inbox';

    protected static ?string $slug = 'signature-inbox';

    protected static ?string $title = 'Awaiting my signature';
}
