<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Identity\BreakGlass;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;

/**
 * The break-glass form (plan 1.7, D10). A plain page outside both panels, so
 * it still works when the panels' own sign-in can't. 404 from anywhere not
 * on the allowlist, so it isn't even advertised.
 *
 *   GET  signature/hub/break-glass
 *   POST signature/hub/break-glass   email, password, code
 */
class BreakGlassController extends Controller
{
    public function __construct(private readonly BreakGlass $breakGlass) {}

    public function show(Request $request): View
    {
        abort_unless($this->breakGlass->allows($request->ip()), 404);

        return view('signature::hub.break-glass');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->breakGlass->allows($request->ip()), 404);

        try {
            $this->breakGlass->attempt(
                $request->session(),
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) $request->input('code', ''),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (IdentityException $e) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->to(app(HubRedirector::class)->adminUrl());
    }
}
