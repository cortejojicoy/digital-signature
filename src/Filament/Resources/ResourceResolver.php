<?php

namespace Kukux\DigitalSignature\Filament\Resources;

/**
 * Detects the installed Filament major version and aliases the canonical
 * Kukux\DigitalSignature\Filament\Resources\SignatureResource class name
 * to the matching version-specific implementation under V3\ or V4\.
 *
 * The alias must be registered before any code references the canonical
 * class — that's why the service provider invokes this in register().
 */
class ResourceResolver
{
    public const CANONICAL = \Kukux\DigitalSignature\Filament\Resources\SignatureResource::class;

    /**
     * Returns 3, 4, or 5 — based on which Filament classes are reachable.
     * Defaults to 3 when nothing v4-specific is detected.
     */
    public static function detectFilamentMajor(): int
    {
        // v4 introduced \Filament\Schemas\Schema. v5 keeps it; if Filament ships
        // a v5-only marker class in the future, branch on it here.
        if (class_exists(\Filament\Schemas\Schema::class)) {
            return 4;
        }

        return 3;
    }

    /**
     * @return class-string  The version-specific implementation class name.
     */
    public static function resolveImplementationClass(): string
    {
        return match (static::detectFilamentMajor()) {
            5, 4    => V4\SignatureResource::class,
            default => V3\SignatureResource::class,
        };
    }

    /**
     * Aliases the canonical name to the version-specific implementation.
     * Idempotent — safe to call multiple times.
     */
    public static function registerAlias(): void
    {
        if (class_exists(static::CANONICAL, autoload: false)) {
            return;
        }

        $impl = static::resolveImplementationClass();

        if (! class_exists($impl)) {
            return;
        }

        class_alias($impl, static::CANONICAL);
    }
}
