<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class SignedDocumentsResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Pages\SignedDocuments::class;

    protected const V3 = V3\SignedDocuments::class;

    protected const V4 = V4\SignedDocuments::class;
}
