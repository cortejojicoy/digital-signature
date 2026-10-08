<?php

/*
 * Hub mode only (signature.mode = hub): pair-first onboarding, agent sign-in
 * and break-glass. Loaded by Hub\HubIdentityServiceProvider.
 * See docs/hub/identity.md.
 */

use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\AgentLoginController;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\BreakGlassController;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\GuestPairingController;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\HubLoginController;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\LandingRedirectController;
use Kukux\DigitalSignature\Http\Middleware\AuthenticateAgent;
use Kukux\DigitalSignature\Http\Middleware\EnsureAgentVersion;

// The browser: the landing page's scripts. `web` for the session that binds
// a pairing or a sign-in to this browser; the per-IP limit on starting a
// pairing (hub.pair_rate_limit) is GuestPairing's own.
Route::prefix('signature/hub')
    ->middleware(['web'])
    ->name('signature.hub.')
    ->group(function () {
        Route::middleware('throttle:120,1')->group(function () {
            Route::post('pairings', [GuestPairingController::class, 'store'])->name('pairings.store');
            Route::get('pairings/{uuid}', [GuestPairingController::class, 'show'])->whereUuid('uuid')->name('pairings.show');
            Route::post('pairings/{uuid}/confirm', [GuestPairingController::class, 'confirm'])->whereUuid('uuid')->name('pairings.confirm');
            Route::post('pairings/{uuid}/cancel', [GuestPairingController::class, 'cancel'])->whereUuid('uuid')->name('pairings.cancel');

            Route::post('login/challenges', [HubLoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
            Route::get('login/challenges/{uuid}', [HubLoginController::class, 'show'])->whereUuid('uuid')->name('login.show');
        });

        // Where a guest is sent to sign in (OAuth authorize uses this name).
        Route::get('landing', LandingRedirectController::class)->name('landing');

        Route::get('break-glass', [BreakGlassController::class, 'show'])->name('break-glass');
        Route::post('break-glass', [BreakGlassController::class, 'store'])->middleware('throttle:10,1')->name('break-glass.store');
    });

// The agent: outside `web` (no cookies, no CSRF), authenticated like
// jobs/{job}/claim. Wire contract: docs/hub/contracts.md §4.
Route::prefix('signature/agent')
    ->middleware([EnsureAgentVersion::class, AuthenticateAgent::class, 'throttle:120,1'])
    ->name('signature.agent.')
    ->group(function () {
        Route::post('logins/{challenge}/claim', [AgentLoginController::class, 'claim'])->whereUuid('challenge')->name('logins.claim');
    });
