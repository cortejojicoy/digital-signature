<?php

namespace Kukux\DigitalSignature\Support;

use Composer\InstalledVersions;

/**
 * Single source of truth for "which Filament are we running on?".
 *
 * Every version-sensitive branch in this package asks this class rather
 * than probing for classes inline. Detection order:
 *
 *   1. An explicit override (config or ::fake()) — used by the test suite
 *      to exercise a branch the installed version wouldn't reach.
 *   2. Composer's installed-versions manifest — the authoritative answer,
 *      and the only one that can tell 5 apart from 4.
 *   3. A class probe fallback, for installs where the manifest isn't
 *      readable (e.g. a merged/ad-hoc autoloader).
 *
 * The manifest step matters: v4 and v5 share the Schemas namespace, so a
 * class probe alone reports both as 4. That was the behaviour before this
 * class existed and it made "supports v5" unverifiable.
 */
final class FilamentVersion
{
    private static ?int $override = null;

    private static ?int $cached = null;

    /**
     * Installed Filament major version — 3, 4, or 5.
     *
     * Unknown / not installed falls back to 3, matching the package's historic
     * default (the oldest supported line is the safest guess because its
     * component base classes still exist in later versions).
     */
    public static function major(): int
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (self::$cached !== null) {
            return self::$cached;
        }

        return self::$cached = self::detect();
    }

    /**
     * True when the installed version routes forms/infolists through
     * `Filament\Schemas\Schema` (v4+) rather than the separate
     * `Filament\Forms\Form` / `Filament\Infolists\Infolist` objects (v3).
     */
    public static function usesSchemas(): bool
    {
        return self::major() >= 4;
    }

    /**
     * True when table row actions must extend `Filament\Tables\Actions\Action`
     * (v3) instead of the unified `Filament\Actions\Action` (v4+).
     */
    public static function usesLegacyTableActions(): bool
    {
        return self::major() === 3;
    }

    /**
     * Namespace segment used to pick a version-specific implementation.
     * v5 currently shares v4's implementations — see docs/signatory-routing.md
     * for when to split a V5 namespace out.
     */
    public static function implementationNamespace(): string
    {
        return self::usesSchemas() ? 'V4' : 'V3';
    }

    /**
     * Force a version for the duration of a test. Pass null to restore
     * real detection.
     */
    public static function fake(?int $major): void
    {
        self::$override = $major;
    }

    /**
     * Drop the memoized detection result — call after changing the
     * `signature.filament_version` config at runtime.
     */
    public static function flush(): void
    {
        self::$cached = null;
    }

    // -------------------------------------------------------------------------

    private static function detect(): int
    {
        // Guarded: this runs from the service provider's register(), which can
        // execute before the config repository is bound (or entirely outside a
        // Laravel app, e.g. a standalone script). Version detection must not
        // depend on the container being ready.
        $configured = null;

        try {
            if (function_exists('app') && app()->bound('config')) {
                $configured = config('signature.filament_version');
            }
        } catch (\Throwable) {
            $configured = null;
        }

        if ($configured !== null && $configured !== '') {
            return (int) $configured;
        }

        $fromComposer = self::detectFromComposer();

        if ($fromComposer !== null) {
            return $fromComposer;
        }

        return self::detectFromClassProbe();
    }

    private static function detectFromComposer(): ?int
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        foreach (['filament/filament', 'filament/support'] as $package) {
            try {
                if (! InstalledVersions::isInstalled($package)) {
                    continue;
                }

                $version = InstalledVersions::getPrettyVersion($package)
                    ?? InstalledVersions::getVersion($package);
            } catch (\Throwable) {
                continue;
            }

            if (! is_string($version)) {
                continue;
            }

            // Handles "3.2.1", "v4.0.0-beta1", "5.5.0.0", "dev-main" (no match).
            if (preg_match('/^v?(\d+)\./', ltrim($version), $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private static function detectFromClassProbe(): int
    {
        // v4 introduced \Filament\Schemas\Schema and v5 kept it, so this
        // probe cannot distinguish the two — it only establishes "4 or later".
        if (class_exists(\Filament\Schemas\Schema::class)) {
            return 4;
        }

        return 3;
    }
}
