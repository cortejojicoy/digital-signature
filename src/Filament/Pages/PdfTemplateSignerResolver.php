<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class PdfTemplateSignerResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Pages\PdfTemplateSigner::class;

    protected const V3 = V3\PdfTemplateSigner::class;

    protected const V4 = V4\PdfTemplateSigner::class;
}
