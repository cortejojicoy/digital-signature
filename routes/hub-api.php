<?php

use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Hub\Api\HealthController;
use Kukux\DigitalSignature\Hub\Api\PeopleController;
use Kukux\DigitalSignature\Hub\Api\SignatureController;
use Kukux\DigitalSignature\Hub\OAuth\AuthenticateHubToken;
use Kukux\DigitalSignature\Hub\OAuth\OAuthController;
use Kukux\DigitalSignature\Hub\Signing\SignRequestController;

/*
|--------------------------------------------------------------------------
| Hub API (SIGNATURE_MODE=hub) — docs/hub/api.md
|--------------------------------------------------------------------------
| Loaded by Hub\HubApiServiceProvider. Everything an app calls is outside
| the `web` group: apps have no cookies, so CSRF would refuse every POST, and
| their trust comes from client secrets and bearer tokens. Only authorize is
| a browser page, and it needs the hub's web session.
*/

$sub = '[A-Za-z0-9._:\-]{1,64}';

Route::prefix('signature/hub')->name('signature.hub.')->group(function () use ($sub) {

    // ── OAuth for apps (no Passport) ─────────────────────────────────────
    Route::post('oauth/token', [OAuthController::class, 'token'])
        ->middleware('throttle:signature-hub-token')
        ->name('oauth.token');

    Route::get('oauth/authorize', [OAuthController::class, 'authorize'])
        ->middleware(['web', 'throttle:60,1'])
        ->name('oauth.authorize');

    Route::get('userinfo', [OAuthController::class, 'userinfo'])
        ->middleware([AuthenticateHubToken::class.':person', 'throttle:signature-hub-api'])
        ->name('userinfo');

    // ── API v1 ──────────────────────────────────────────────────────────
    Route::prefix('api/v1')->name('api.')->group(function () use ($sub) {

        // For the uptime monitor (R1, R13). No auth; booleans only.
        Route::get('health', HealthController::class)
            ->middleware('throttle:60,1')
            ->name('health');

        Route::middleware([AuthenticateHubToken::class.':app,signatures.read', 'throttle:signature-hub-api'])->group(function () use ($sub) {
            Route::get('people', [PeopleController::class, 'lookup'])->name('people.lookup');
            Route::get('people/{sub}', [PeopleController::class, 'show'])->where('sub', $sub)->name('people.show');
            Route::post('people/{sub}/link', [PeopleController::class, 'link'])->where('sub', $sub)->name('people.link');

            Route::get('people/{sub}/signature', [SignatureController::class, 'show'])->where('sub', $sub)->name('signature.show');
            Route::get('people/{sub}/signature/image', [SignatureController::class, 'image'])->where('sub', $sub)->name('signature.image');

            Route::get('certificates/{fingerprint}', [SignatureController::class, 'certificate'])->name('certificates.show');
        });

        Route::middleware([AuthenticateHubToken::class.':app,sign', 'throttle:signature-hub-api'])->group(function () {
            Route::post('sign-requests', [SignRequestController::class, 'store'])->name('sign-requests.store');
            Route::get('sign-requests/{id}', [SignRequestController::class, 'show'])->whereUuid('id')->name('sign-requests.show');
        });
    });
});
