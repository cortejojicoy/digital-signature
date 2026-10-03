<?php

namespace Kukux\DigitalSignature\Filament\Pages\V3;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsSignedDocuments;

/**
 * Filament v3: Page::$view is a STATIC property.
 */
class SignedDocuments extends Page
{
    use IsSignedDocuments;

    protected static string $view = 'signature::filament.pages.signed-documents';

    protected static ?string $slug = 'signed-documents';

    protected static ?string $title = 'Documents I\'ve signed';
}
