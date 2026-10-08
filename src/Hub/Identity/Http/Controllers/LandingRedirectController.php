<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;

/**
 * "Go sign in": the person panel's landing, wherever that is.
 *
 * Used as the admin panel's "login page" (there is none, plan 1.8) and as
 * `signature.hub.landing`, where OAuth authorize sends a guest. Whoever sent
 * the guest here already stored the intended URL, so they come back to it.
 */
class LandingRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->to(app(HubRedirector::class)->landingUrl());
    }
}
