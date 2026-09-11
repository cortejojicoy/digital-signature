<?php

namespace Kukux\DigitalSignature\Filament\Pages\V4;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsPdfTemplateSigner;

/**
 * Filament v4 / v5: Page::$view is an INSTANCE property.
 */
class PdfTemplateSigner extends Page
{
    use IsPdfTemplateSigner;

    protected string $view = 'signature::filament.pages.pdf-template-signer';

    protected static ?string $slug = 'signature-templates/{templateKey}/sign/{signatureUuid}';

    protected static bool $shouldRegisterNavigation = false;
}
