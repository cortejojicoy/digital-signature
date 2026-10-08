<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Closure;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Kukux\DigitalSignature\Hub\Filament\HubPanels;
use Kukux\DigitalSignature\Models\Identity;

/**
 * Who may use which hub panel (plan 1.8). One place, so the User model's
 * canAccessPanel(), the redirect after sign-in and the Profile's "Open admin
 * panel" button can't disagree.
 *
 *   person panel  any account whose identity isn't retired, separated or rejected
 *   admin panel   a verified identity AND super_admin; or a break-glass session
 *
 * The host's User model gets this through Concerns\HasHubPanelAccess.
 */
final class HubAccess
{
    /** Set on the session by a break-glass sign-in (D10). Admin panel only. */
    public const BREAK_GLASS = 'signature.hub.break_glass';

    private static ?Closure $superAdminUsing = null;

    /**
     * How to tell a super_admin when the User model has no hasRole() (no
     * Shield / spatie/laravel-permission): fn (User $user): bool.
     */
    public static function superAdminUsing(?Closure $callback): void
    {
        self::$superAdminUsing = $callback;
    }

    public static function canAccessPanel(Authenticatable $user, Panel|string $panel): bool
    {
        $id = $panel instanceof Panel ? $panel->getId() : $panel;

        return match ($id) {
            HubPanels::adminPanelId() => self::canUseAdminPanel($user),
            HubPanels::personPanelId() => self::canUsePersonPanel($user),
            default => false,
        };
    }

    public static function canUsePersonPanel(Authenticatable $user): bool
    {
        $identity = Identity::forUser((int) $user->getAuthIdentifier());

        // No identity row yet (an account made outside pairing): it gets one
        // when it identifies, so let it in to do that.
        return $identity === null || $identity->isUsable();
    }

    public static function canUseAdminPanel(Authenticatable $user): bool
    {
        if (! self::isSuperAdmin($user)) {
            return false;
        }

        if (self::isBreakGlassSession()) {
            return true;
        }

        return (bool) Identity::forUser((int) $user->getAuthIdentifier())?->isVerified();
    }

    public static function isSuperAdmin(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::$superAdminUsing !== null) {
            return (bool) (self::$superAdminUsing)($user);
        }

        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole((string) config('signature.hub.super_admin_role', 'super_admin'));
        }

        return false;
    }

    /** Whether the admin panel should be offered (Profile header, redirect). */
    public static function offersAdminPanel(?Authenticatable $user): bool
    {
        return $user !== null && self::canUseAdminPanel($user);
    }

    public static function isBreakGlassSession(): bool
    {
        return app()->bound('session') && (bool) session(self::BREAK_GLASS, false);
    }
}
