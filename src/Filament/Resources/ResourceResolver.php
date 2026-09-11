<?php

namespace Kukux\DigitalSignature\Filament\Resources;

use Kukux\DigitalSignature\Filament\Support\ComponentResolver;

/**
 * Aliases the canonical
 * Kukux\DigitalSignature\Filament\Resources\SignatureResource class name to
 * the V3 or V4 implementation, based on the installed Filament major.
 *
 * The alias must be registered before any code references the canonical
 * class — that's why the service provider invokes this in register().
 */
class ResourceResolver extends ComponentResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Resources\SignatureResource::class;

    protected const V3 = V3\SignatureResource::class;

    protected const V4 = V4\SignatureResource::class;
}
