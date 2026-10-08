<?php

use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Hub\Signing\CompleteHubSignRequest;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;

/*
 * Standalone (the default) never sees the hub API: no routes, no listener,
 * no commands.
 */

it('registers nothing of the hub API outside hub mode', function () {
    expect(config('signature.mode'))->toBe('standalone')
        ->and(Route::has('signature.hub.oauth.token'))->toBeFalse()
        ->and(Route::has('signature.hub.api.health'))->toBeFalse()
        ->and(app()->bound(HubNotifier::class))->toBeFalse()
        ->and((fn () => $this->listeners[AgentJobUpdated::class] ?? [])->call(app('events')))->not->toContain(CompleteHubSignRequest::class)
        ->and(array_key_exists('signature:hub-webhooks', \Illuminate\Support\Facades\Artisan::all()))->toBeFalse();

    $this->getJson('/signature/hub/api/v1/health')->assertNotFound();
});
