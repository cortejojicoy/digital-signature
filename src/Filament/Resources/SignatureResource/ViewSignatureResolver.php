<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * `SignatureResource\Pages\ViewSignature` is the name both V3\SignatureResource
 * and V4\SignatureResource route to; it comes into being as an alias of the
 * matching implementation. See Pages\Concerns\IsViewSignature for why the class
 * has to be split at all.
 */
class ViewSignatureResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\ViewSignature::class;

    protected const V3 = Pages\V3\ViewSignature::class;

    protected const V4 = Pages\V4\ViewSignature::class;
}
