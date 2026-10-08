<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;

/**
 * Filament's LoginResponse in hub mode (Filament 4/5): any Filament sign-in
 * lands where HubRedirector says, so both panels agree.
 */
class HubLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->to(app(HubRedirector::class)->afterSignIn($request->user()));
    }
}
