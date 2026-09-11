<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

class SignatureInboxResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Pages\SignatureInbox::class;

    protected const V3 = V3\SignatureInbox::class;

    protected const V4 = V4\SignatureInbox::class;
}
