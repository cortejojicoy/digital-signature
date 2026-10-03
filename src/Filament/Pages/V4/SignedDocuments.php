<?php

namespace Kukux\DigitalSignature\Filament\Pages\V4;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsSignedDocuments;

/**
 * Filament v4 / v5: Page::$view is an INSTANCE property.
 */
class SignedDocuments extends Page
{
    use IsSignedDocuments;

    protected string $view = 'signature::filament.pages.signed-documents';

    protected static ?string $slug = 'signed-documents';

    protected static ?string $title = 'Documents I\'ve signed';
}
