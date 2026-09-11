<?php

namespace Kukux\DigitalSignature\Filament\Support;

use Kukux\DigitalSignature\Support\FilamentVersion;

/**
 * Base for the "one canonical class name, several version-specific
 * implementations" pattern this package uses for every Filament component
 * whose base class moved between majors.
 *
 * A subclass declares the canonical name and the V3/V4 implementations;
 * the service provider calls `registerAlias()` in register() — before
 * anything can reference the canonical name — and host apps keep importing
 * a single stable class name regardless of the Filament version installed.
 *
 *   final class ActionResolver extends ComponentResolver
 *   {
 *       public const CANONICAL = \…\Filament\Actions\SignDocumentAction::class;
 *       protected const V3 = \…\Filament\Actions\V3\SignDocumentAction::class;
 *       protected const V4 = \…\Filament\Actions\V4\SignDocumentAction::class;
 *   }
 *
 * Why aliasing and not a factory: Filament components are used statically
 * (`SignDocumentAction::make()`) and are type-hinted in host code, so the
 * name itself has to resolve to the right class.
 */
abstract class ComponentResolver
{
    /**
     * The class name host apps import. Must NOT exist as a real class —
     * it only comes into being via class_alias().
     */
    public const CANONICAL = '';

    /** Implementation used on Filament v3. */
    protected const V3 = '';

    /** Implementation used on Filament v4 and v5. */
    protected const V4 = '';

    /**
     * Returns 3, 4, or 5 for the installed Filament.
     *
     * @deprecated Prefer FilamentVersion::major(); kept so existing callers
     *             (and any host code that reached into the resolver) keep
     *             working unchanged.
     */
    public static function detectFilamentMajor(): int
    {
        return FilamentVersion::major();
    }

    /**
     * @return class-string The version-specific implementation class name.
     */
    public static function resolveImplementationClass(): string
    {
        return FilamentVersion::usesSchemas()
            ? static::V4
            : static::V3;
    }

    /**
     * Alias the canonical name to the version-specific implementation.
     * Idempotent, and a no-op when the implementation is missing so a
     * partial install degrades to "component unavailable" rather than a
     * fatal error during boot.
     */
    public static function registerAlias(): void
    {
        if (static::CANONICAL === '') {
            throw new \LogicException(
                static::class.' must declare a CANONICAL class name.'
            );
        }

        if (class_exists(static::CANONICAL, autoload: false)) {
            return;
        }

        $implementation = static::resolveImplementationClass();

        if ($implementation === '' || ! class_exists($implementation)) {
            return;
        }

        class_alias($implementation, static::CANONICAL);
    }
}
