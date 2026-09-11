<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * Filament changed Page::$view from a static to an instance property in v4,
 * and PHP will not let one class satisfy both. Each page is therefore split
 * and aliased the same way the resources and actions are.
 */
class PdfTemplateDesignerResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner::class;

    protected const V3 = V3\PdfTemplateDesigner::class;

    protected const V4 = V4\PdfTemplateDesigner::class;
}
