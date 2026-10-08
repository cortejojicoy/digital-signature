<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Hub\Specimens\HubRevocation;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\HubWebhook;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;

uses(HubApiEnvironment::class);

beforeEach(function () {
    $this->setUpHubApi();
    Http::fake(['*' => Http::response('', 204)]);
});

/*
 * Specimens, revocation and the mirrors bucket (plan A12, R4).
 */

it('publishes a new specimen to the specimen disk and tells holder apps', function () {
    Storage::fake('specimens');
    config(['signature.hub.specimen_disk' => 'specimens']);

    $app = $this->registerApp();
    $specimen = $this->signer();
    HubHolder::link($app['app']->id, 'p-juan');

    app(SpecimenService::class)->published($specimen);

    expect(Storage::disk('specimens')->get($specimen->image_path))->toBe(Storage::disk('testing')->get($specimen->image_path))
        ->and(HubWebhook::query()->sole())
        ->event->toBe('signature.updated')
        ->payload->toBe(['sub' => 'p-juan', 'uuid' => $specimen->uuid, 'image_sha256' => $specimen->image_hash]);
});

it('revokes the signature and certificate, deletes every mirror object, notifies and audits', function () {
    Storage::fake('mirrors');
    config(['signature.hub.mirrors_disk' => 'mirrors']);

    $performance = $this->registerApp();
    $amp = $this->registerApp('amp');
    $specimen = $this->signer();
    $other = $this->signer('p-maria', 43);
    HubHolder::link($performance['app']->id, 'p-juan');

    $cert = UserCertificate::create([
        'user_id' => 42, 'pfx_path' => 'certs/42.pfx', 'fingerprint' => str_repeat('ab', 32),
        'issued_at' => now(), 'expires_at' => now()->addYear(),
    ]);

    $mirrors = Storage::disk('mirrors');
    $mirrors->put("performance/{$specimen->uuid}.png", 'png');
    $mirrors->put("amp/{$specimen->uuid}.png", 'png');
    $mirrors->put("performance/{$other->uuid}.png", 'png');

    app(HubRevocation::class)->revokeSignature(42, 'fraud', actorId: 43);

    expect($specimen->refresh())->status->toBe('revoked')->revoked_at->not->toBeNull()
        ->and($cert->refresh()->revoked_at)->not->toBeNull()
        ->and($mirrors->exists("performance/{$specimen->uuid}.png"))->toBeFalse()
        ->and($mirrors->exists("amp/{$specimen->uuid}.png"))->toBeFalse()
        // Someone else's mirror is untouched.
        ->and($mirrors->exists("performance/{$other->uuid}.png"))->toBeTrue();

    // Only the holder hears about it.
    $webhook = HubWebhook::query()->sole();
    expect($webhook->app_id)->toBe($performance['app']->id)
        ->and($webhook->event)->toBe('signature.revoked')
        ->and($webhook->payload)->toBe(['sub' => 'p-juan', 'uuid' => $specimen->uuid, 'reason' => 'fraud']);

    $audit = SignatureAudit::query()->where('event', SignatureAudit::SIGNATURE_REVOKED)->sole();
    expect($audit->subject_user_id)->toBe(42)
        ->and($audit->actor_user_id)->toBe(43)
        ->and($audit->personnel_key)->toBe('p-juan')
        ->and($audit->context['reason'])->toBe('fraud')
        ->and($audit->context['mirror_objects_deleted'])->toEqualCanonicalizing([
            "performance/{$specimen->uuid}.png", "amp/{$specimen->uuid}.png",
        ]);

    // And the API no longer has a signature to give or to sign with.
    $token = $this->appToken($performance['app']);
    $this->getJson('/signature/hub/api/v1/people/p-juan/signature', $this->bearer($token))->assertNotFound();
});

it('revokes without a mirrors disk, relying on webhooks', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    HubHolder::link($app['app']->id, 'p-juan');

    app(HubRevocation::class)->revokeSignature(42, 'separated');

    expect($specimen->refresh()->status)->toBe('revoked')
        ->and(HubWebhook::query()->sole()->event)->toBe('signature.revoked')
        ->and(SignatureAudit::query()->where('event', SignatureAudit::SIGNATURE_REVOKED)->sole()->actor_type)->not->toBe('user');
});

it('finds and deletes orphan mirror objects', function () {
    Storage::fake('mirrors');
    config(['signature.hub.mirrors_disk' => 'mirrors']);

    $app = $this->registerApp();
    $held = $this->signer();
    $notHeld = $this->signer('p-maria', 43);
    HubHolder::link($app['app']->id, 'p-juan');

    $mirrors = Storage::disk('mirrors');
    $mirrors->put("performance/{$held->uuid}.png", 'png');
    $mirrors->put("performance/{$notHeld->uuid}.png", 'png');
    $mirrors->put('performance/stale.png', 'png');

    $this->artisan('signature:hub-audit-mirrors')
        ->expectsOutputToContain("orphan: performance/{$notHeld->uuid}.png")
        ->expectsOutputToContain('orphan: performance/stale.png')
        ->assertFailed();

    $this->artisan('signature:hub-audit-mirrors', ['--delete-orphans' => true])
        ->expectsOutputToContain('Deleted 2 orphan object(s).')
        ->assertSuccessful();

    expect($mirrors->files('performance'))->toBe(["performance/{$held->uuid}.png"]);

    $this->artisan('signature:hub-audit-mirrors')->expectsOutputToContain('No orphans.')->assertSuccessful();
});

it('registers an app from the console and prints its secrets once', function () {
    $this->artisan('signature:hub-app', [
        'client_id'  => 'performance',
        'name'       => 'UPLB Performance',
        '--redirect' => ['https://performance.uplb.edu.ph/signature/hub/callback'],
        '--webhook'  => 'https://performance.uplb.edu.ph/signature/hub/webhook',
    ])
        ->expectsOutputToContain('SIGNATURE_HUB_CLIENT_ID=performance')
        ->expectsOutputToContain('SIGNATURE_HUB_CLIENT_SECRET=')
        ->expectsOutputToContain('SIGNATURE_HUB_WEBHOOK_SECRET=')
        ->assertSuccessful();

    $app = HubApp::query()->sole();
    expect($app->scopes)->toBe(['signatures.read', 'sign'])
        ->and($app->mirror_prefix)->toBe('performance')
        ->and(strlen($app->secret_hash))->toBe(64)
        ->and($app->getRawOriginal('webhook_secret'))->not->toBe($app->webhook_secret);   // encrypted at rest

    $this->artisan('signature:hub-app', ['client_id' => 'performance', 'name' => 'Again'])->assertFailed();
    $this->artisan('signature:hub-app', ['client_id' => 'amp', 'name' => 'AMP', '--scopes' => ['admin']])->assertFailed();
    $this->artisan('signature:hub-app', ['client_id' => 'amp', 'name' => 'AMP', '--webhook' => 'http://amp.uplb.edu.ph/hook'])->assertFailed();
});
