<?php

namespace Kukux\DigitalSignature\Support;

use Filament\Facades\Filament;
use Kukux\DigitalSignature\SignaturePlugin;
use Throwable;

/**
 * Resolved settings for the floating launcher.
 *
 * Every reader goes through here rather than touching config directly,
 * because two sources can answer and they have to be consulted in a fixed
 * order: the plugin instance registered on the *current* panel first (so
 * `SignaturePlugin::make()->withoutFloatingLauncher()` wins on that panel
 * alone), then the package config as the app-wide default.
 *
 * The plugin lookup is deliberately forgiving. These settings are read from
 * static navigation methods on pages and resources, which Filament also calls
 * outside a panel context (route caching, `about`, a queued job that touches a
 * resource). Throwing there would turn "no panel bound yet" into a 500 on an
 * unrelated request, so a missing panel or unregistered plugin simply means
 * "fall back to config".
 */
final class LauncherSettings
{
    public static function enabled(): bool
    {
        $plugin = self::plugin();

        if ($plugin !== null && $plugin->hasLauncherOverride()) {
            return $plugin->wantsLauncher();
        }

        return (bool) config('signature.launcher.enabled', true);
    }

    /**
     * True when the launcher has taken over as the entry point and the sidebar
     * items should stand down. False either because the host asked to keep
     * both, or because there is no launcher to replace them with.
     */
    public static function replacesNavigation(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return (bool) config('signature.launcher.replaces_navigation', true);
    }

    /** One of: bottom-right, bottom-left, top-right, top-left. */
    public static function position(): string
    {
        $position = (string) config('signature.launcher.position', 'bottom-right');

        return in_array($position, ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)
            ? $position
            : 'bottom-right';
    }

    public static function icon(): string
    {
        return (string) config('signature.launcher.icon', 'heroicon-o-pencil-square');
    }

    public static function label(): string
    {
        return (string) config('signature.launcher.label', 'Signatures');
    }

    /** Brand hex for the button, or null to use the built-in neutral. */
    public static function color(): ?string
    {
        $color = config('signature.launcher.color');

        return is_string($color) && $color !== '' ? $color : null;
    }

    /** Badge refresh interval in seconds; 0 means don't poll. */
    public static function pollSeconds(): int
    {
        return max(0, (int) config('signature.launcher.poll_seconds', 60));
    }

    /**
     * Hide the button entirely when the user has nothing waiting. Off by
     * default: the launcher is also how someone reaches their signature
     * library, and a control that vanishes is a control users stop trusting.
     */
    public static function hideWhenEmpty(): bool
    {
        return (bool) config('signature.launcher.hide_when_empty', false);
    }

    public static function plugin(): ?SignaturePlugin
    {
        try {
            $plugin = Filament::getCurrentPanel()?->getPlugin('signature');
        } catch (Throwable) {
            return null;
        }

        return $plugin instanceof SignaturePlugin ? $plugin : null;
    }
}
