<?php

namespace Kukux\DigitalSignature\Filament\Pages\V3;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsPdfTemplateDesigner;

/**
 * Filament v3: Page::$view is a STATIC property.
 */
class PdfTemplateDesigner extends Page
{
    use IsPdfTemplateDesigner;

    protected static string $view = 'signature::filament.pages.pdf-template-designer';

    protected static ?string $slug = 'signature-templates/{templateKey}/design';

    protected static bool $shouldRegisterNavigation = false;
}
