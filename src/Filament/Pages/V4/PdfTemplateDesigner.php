<?php

namespace Kukux\DigitalSignature\Filament\Pages\V4;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Filament\Pages\Concerns\IsPdfTemplateDesigner;

/**
 * Filament v4 / v5: Page::$view is an INSTANCE property. Declaring it static
 * here — as this package did before the compat layer existed — is a PHP fatal
 * ("Cannot redeclare non static Filament\Pages\Page::$view as static").
 */
class PdfTemplateDesigner extends Page
{
    use IsPdfTemplateDesigner;

    protected string $view = 'signature::filament.pages.pdf-template-designer';

    protected static ?string $slug = 'signature-templates/{templateKey}/design';

    protected static bool $shouldRegisterNavigation = false;
}
