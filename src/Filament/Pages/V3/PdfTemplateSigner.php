<?php

namespace Kukux\DigitalSignature\Filament\Pages\V3;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsPdfTemplateSigner;

/**
 * Filament v3: Page::$view is a STATIC property.
 */
class PdfTemplateSigner extends Page
{
    use IsPdfTemplateSigner;

    protected static string $view = 'signature::filament.pages.pdf-template-signer';

    protected static ?string $slug = 'signature-templates/{templateKey}/sign/{signatureUuid}';

    protected static bool $shouldRegisterNavigation = false;
}
