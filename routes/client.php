<?php

use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Client\Http\HubLoginController;
use Kukux\DigitalSignature\Client\Http\HubWebhookController;

/*
|--------------------------------------------------------------------------
| Client mode (docs/hub/client.md)
|--------------------------------------------------------------------------
| Loaded by Client\ClientServiceProvider only when SIGNATURE_MODE=client.
*/

// "Sign in with UPLB Signature": authorization code + PKCE against the hub.
Route::prefix('signature/hub')
    ->middleware(['web', 'throttle:30,1'])
    ->name('signature.hub.')
    ->group(function () {
        Route::get('login', [HubLoginController::class, 'redirect'])->name('login');
        Route::get('callback', [HubLoginController::class, 'callback'])->name('callback');
    });

// Events from the hub. Deliberately NOT in the `web` group: the hub has no
// session or CSRF token; the HMAC signature and timestamp are its trust.
Route::post('signature/hub/webhook', HubWebhookController::class)
    ->middleware('throttle:240,1')
    ->name('signature.hub.webhook');
