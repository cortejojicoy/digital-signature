<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Kukux\DigitalSignature\Hub\Filament\HubPanels;
use Kukux\DigitalSignature\Models\Identity;
use Throwable;

/**
 * Where a person lands after signing in (plan 1.8), in one place: the agent
 * sign-in poll, the pairing confirm, "Who are you?" and Filament's
 * LoginResponse all ask here, so the two panels never disagree.
 *
 *   0. not identified yet       → "Who are you?" (the intended URL waits)
 *   1. an intended URL          → there first: an app's /oauth/authorize must
 *                                 never be hijacked by the admin redirect
 *   2. may use the admin panel  → hub.admin_path
 *   3. everyone else            → the person panel (Profile)
 */
class HubRedirector
{
    public function afterSignIn(?Authenticatable $user): string
    {
        if ($user === null) {
            return $this->landingUrl();
        }

        $identity = Identity::forUser((int) $user->getAuthIdentifier());

        if (! HubAccess::isBreakGlassSession() && ($identity === null || ! $identity->isIdentified())) {
            return $this->identifyUrl();
        }

        $intended = session()->pull('url.intended');

        // An intended admin URL only for someone the admin panel will let in;
        // anyone else would just meet a 403 there.
        if (is_string($intended) && $intended !== ''
            && (! $this->isAdminUrl($intended) || HubAccess::canUseAdminPanel($user))) {
            return $intended;
        }

        return HubAccess::canUseAdminPanel($user) ? $this->adminUrl() : $this->profileUrl();
    }

    public function adminUrl(): string
    {
        return url((string) config('signature.hub.admin_path', '/admin'));
    }

    public function profileUrl(): string
    {
        return $this->pageUrl(\Kukux\DigitalSignature\Hub\Filament\Pages\Profile::class, (string) config('signature.hub.profile_path', '/'));
    }

    public function identifyUrl(): string
    {
        return $this->pageUrl(\Kukux\DigitalSignature\Hub\Filament\Pages\Identify::class, '/identify');
    }

    public function landingUrl(): string
    {
        try {
            $panel = filament()->getPanel(HubPanels::personPanelId());

            return $panel->getLoginUrl() ?? url('/');
        } catch (Throwable) {
            return url('/');
        }
    }

    private function pageUrl(string $page, string $fallback): string
    {
        try {
            return $page::getUrl(panel: HubPanels::personPanelId());
        } catch (Throwable) {
            return url($fallback);
        }
    }

    private function isAdminUrl(string $url): bool
    {
        $admin = '/'.trim((string) config('signature.hub.admin_path', '/admin'), '/');
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === $admin || str_starts_with($path, $admin.'/');
    }
}
