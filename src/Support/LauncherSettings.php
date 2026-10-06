<?php

namespace Kukux\DigitalSignature\Support;

use Filament\Facades\Filament;
use Kukux\DigitalSignature\SignaturePlugin;
use Throwable;

/**
 * Resolved settings for the floating launcher.
 *
 * Every reader goes through here rather than touching config directly,
 * because several sources can answer and they have to be consulted in a fixed
 * order: the plugin instance registered on the *current* panel first (so
 * `SignaturePlugin::make()->withoutFloatingLauncher()` wins on that panel
 * alone), then the package config as the app-wide default.
 *
 * Placement — the corner and the offsets — has one more layer in front: the
 * signed-in user's own choice from the drawer's Settings tab. Those readers
 * take the user's preferences (UserPreference::for()) as an argument rather
 * than looking them up, so the caller loads them once per request and this
 * class stays free of auth and query state.
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

    public const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];

    /**
     * Whether users may move their own launcher from the drawer's Settings
     * tab. When off, the tab is hidden and saved choices are ignored, so the
     * config placement applies to everyone again.
     */
    public static function customizable(): bool
    {
        return (bool) config('signature.launcher.customizable', true);
    }

    /**
     * One of POSITIONS: the user's choice, else config.
     *
     * @param  array<string, mixed>  $preferences
     */
    public static function position(array $preferences = []): string
    {
        $chosen = self::preference($preferences, 'position');

        if (is_string($chosen) && in_array($chosen, self::POSITIONS, true)) {
            return $chosen;
        }

        return self::defaultPosition();
    }

    /** The config placement, ignoring any user choice. */
    public static function defaultPosition(): string
    {
        $position = (string) config('signature.launcher.position', 'bottom-right');

        return in_array($position, self::POSITIONS, true) ? $position : 'bottom-right';
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
     * How wide the slide-over is on desktop.
     *
     * Wide by default because the panel is no longer only a queue: it carries
     * the signature library and the rendered PDF the signatory is being asked
     * to sign, and a signature applied to a document nobody could read is the
     * failure mode this width exists to prevent. The CSS caps it at the
     * viewport and goes full-bleed on small screens, so an over-large value
     * degrades rather than overflows.
     */
    public static function width(): string
    {
        return self::cssLength(config('signature.launcher.width'), '56rem');
    }

    /**
     * How wide the slide-over grows while "Manage signatures" is open.
     *
     * Managing puts the signature list beside its details and the templates it
     * can be applied to, which needs more room than the queue does. Capped at
     * the viewport like width().
     */
    public static function manageWidth(): string
    {
        return self::cssLength(config('signature.launcher.manage_width'), '72rem');
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

    /**
     * Distance from the corner before any stacking: the user's choice, else
     * config. Any CSS length.
     *
     * @param  array<string, mixed>  $preferences
     */
    public static function offsetX(array $preferences = []): string
    {
        return self::cssLength(
            self::preference($preferences, 'offset_x'),
            self::cssLength(config('signature.launcher.offset.x'), '1.5rem'),
        );
    }

    /** @param  array<string, mixed>  $preferences */
    public static function offsetY(array $preferences = []): string
    {
        return self::cssLength(
            self::preference($preferences, 'offset_y'),
            self::cssLength(config('signature.launcher.offset.y'), '1.5rem'),
        );
    }

    /**
     * A CSS length as whole pixels, for the Settings tab's sliders. rem and
     * em count as 16px — the browser default, and what the config's own
     * defaults assume.
     */
    public static function toPixels(string $length): int
    {
        if (preg_match('/^(-?\d*\.?\d+)(px|rem|em)?$/', $length, $m) !== 1) {
            return 24;
        }

        $factor = in_array($m[2] ?? '', ['rem', 'em'], true) ? 16 : 1;

        return (int) round((float) $m[1] * $factor);
    }

    /**
     * A user's launcher choice, or null when there is none or the host has
     * turned customising off.
     *
     * @param  array<string, mixed>  $preferences
     */
    private static function preference(array $preferences, string $key): mixed
    {
        if (! self::customizable()) {
            return null;
        }

        return $preferences['launcher'][$key] ?? null;
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
