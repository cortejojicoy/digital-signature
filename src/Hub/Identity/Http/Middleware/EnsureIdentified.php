<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kukux\DigitalSignature\Hub\Filament\HubPanels;
use Kukux\DigitalSignature\Hub\Filament\Pages\Identify;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Models\Identity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until a paired account says who it is, "Who are you?" is the only page it
 * may open (plan 1.2), plus signing out. Retired, separated and rejected
 * accounts get nothing. Added to the person panel's auth middleware by
 * HubPanels; usable on any other hub route too.
 */
class EnsureIdentified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $identity = Identity::forUser((int) $user->getAuthIdentifier());

        if ($identity !== null && ! $identity->isUsable()) {
            abort(403, 'This account can no longer be used. Pair this computer again.');
        }

        if ($identity?->isIdentified() || $this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => 'not_identified', 'message' => 'Tell us who you are first.'], 403);
        }

        return redirect()->to(app(HubRedirector::class)->identifyUrl());
    }

    private function isAllowed(Request $request): bool
    {
        $panel = HubPanels::personPanelId();

        return $request->routeIs('filament.'.$panel.'.pages.'.Identify::SLUG, "filament.{$panel}.auth.logout");
    }
}
