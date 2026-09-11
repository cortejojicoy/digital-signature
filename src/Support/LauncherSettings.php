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

    /**
     * Whether the button should measure its corner before settling into it.
     *
     * A plugin does not own the corner it is dropped into: host apps put chat
     * widgets, cookie bars and their own FABs there, and landing on top of one
     * makes the launcher look like a bug in the host app rather than a feature
     * of this package.
     */
    public static function avoidOverlap(): bool
    {
        return (bool) config('signature.launcher.avoid_overlap', true);
    }

    /** Distance from the corner before any stacking. Any CSS length. */
    public static function offsetX(): string
    {
        return self::cssLength(config('signature.launcher.offset.x'), '1.5rem');
    }

    public static function offsetY(): string
    {
        return self::cssLength(config('signature.launcher.offset.y'), '1.5rem');
    }

    /** Pixels between the button and whatever it stacks above. */
    public static function gap(): int
    {
        return max(0, (int) config('signature.launcher.gap', 12));
    }

    public static function zIndex(): int
    {
        return (int) config('signature.launcher.z_index', 40);
    }

    /**
     * Selectors the detector should always treat as occupying the corner —
     * for widgets it cannot see, such as ones that render into an iframe or
     * mount long after the page settles.
     *
     * @return array<int, string>
     */
    public static function avoidSelectors(): array
    {
        return self::selectors(config('signature.launcher.avoid', []));
    }

    /**
     * Selectors the detector should never treat as occupying the corner —
     * for full-width toast rails and similar decoration that the size
     * heuristics don't already rule out.
     *
     * @return array<int, string>
     */
    public static function ignoreSelectors(): array
    {
        return self::selectors(config('signature.launcher.ignore', []));
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private static function selectors($value): array
    {
        return array_values(array_filter(
            array_map(
                static fn ($selector): string => trim((string) $selector),
                is_array($value) ? $value : [],
            ),
            static fn (string $selector): bool => $selector !== '',
        ));
    }

    /**
     * Offsets go straight into a style attribute, so anything that isn't a
     * plain CSS length is refused rather than escaped — a config value is not
     * a place to accept arbitrary declarations.
     *
     * @param  mixed  $value
     */
    private static function cssLength($value, string $fallback): string
    {
        $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

        if ($value === '') {
            return $fallback;
        }

        return preg_match('/^-?\d*\.?\d+(px|rem|em|vh|vw|%)?$/', $value) === 1
            ? $value
            : $fallback;
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
