<?php

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;

uses(HubApiEnvironment::class);

beforeEach(fn () => $this->setUpHubApi());

/*
 * People, signatures and certificates for apps (docs/hub/contracts.md §2.2).
 */

// ── health ───────────────────────────────────────────────────────────────

it('reports health without auth', function () {
    $this->getJson('/signature/hub/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => true, 'queue' => true, 'specimen_disk' => true, 'mirrors_disk' => null],
        ]);
});

it('reports degraded with a 503 when a configured disk fails', function () {
    config(['signature.hub.mirrors_disk' => 'does-not-exist']);

    $this->getJson('/signature/hub/api/v1/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'degraded', 'checks' => ['mirrors_disk' => false, 'database' => true]]);
});

// ── people ───────────────────────────────────────────────────────────────

it('needs an app token', function () {
    $this->getJson('/signature/hub/api/v1/people?emp_no=E-1001')
        ->assertStatus(401)->assertJson(['error' => 'invalid_token']);

    $this->getJson('/signature/hub/api/v1/people?emp_no=E-1001', $this->bearer('made-up'))
        ->assertStatus(401);
});

it('looks people up by exact employee number, at most one', function () {
    $app = $this->registerApp();
    $this->person();
    $this->person('p-other', ['name' => 'E-1001 Somebody', 'empNo' => 'E-10010', 'email' => 'other@up.edu.ph']);
    $token = $this->appToken($app['app']);

    $this->getJson('/signature/hub/api/v1/people?emp_no=E-1001', $this->bearer($token))
        ->assertOk()
        ->assertExactJson(['data' => [[
            'sub' => 'p-juan', 'name' => 'Juan dela Cruz', 'email' => 'jdcruz@up.edu.ph',
            'emp_no' => 'E-1001', 'unit' => 'ICS', 'position' => 'Assistant Professor',
        ]]]);

    // A prefix is not a match: this is a lookup, not a search.
    $this->getJson('/signature/hub/api/v1/people?emp_no=E-100', $this->bearer($token))
        ->assertOk()->assertExactJson(['data' => []]);
});

it('looks people up by exact email through their hub account', function () {
    $app = $this->registerApp();
    $this->person();
    $user = $this->account();
    $user->update(['email' => 'jdcruz@up.edu.ph']);
    $token = $this->appToken($app['app']);

    $this->getJson('/signature/hub/api/v1/people?email=jdcruz@up.edu.ph', $this->bearer($token))
        ->assertOk()->assertJsonPath('data.0.sub', 'p-juan')->assertJsonCount(1, 'data');

    $this->getJson('/signature/hub/api/v1/people?email=nobody@up.edu.ph', $this->bearer($token))
        ->assertOk()->assertExactJson(['data' => []]);
});

it('validates the lookup strictly', function () {
    $app = $this->registerApp();
    $token = $this->appToken($app['app']);

    $this->getJson('/signature/hub/api/v1/people', $this->bearer($token))
        ->assertStatus(422)->assertJson(['error' => 'invalid_request']);

    $this->getJson('/signature/hub/api/v1/people?email=not-an-email', $this->bearer($token))
        ->assertStatus(422)->assertJson(['error' => 'invalid_request']);

    $this->getJson('/signature/hub/api/v1/people?email=a@b.ph&emp_no=E-1', $this->bearer($token))
        ->assertStatus(422)->assertJson(['error' => 'invalid_request']);

    $this->getJson('/signature/hub/api/v1/people?emp_no='.urlencode('E%'), $this->bearer($token))
        ->assertStatus(422);
});

it('shows a person, with active, or 404 unknown_person', function () {
    $app = $this->registerApp();
    $this->person();
    $token = $this->appToken($app['app']);

    $this->getJson('/signature/hub/api/v1/people/p-juan', $this->bearer($token))
        ->assertOk()->assertJson(['sub' => 'p-juan', 'active' => true]);

    $this->getJson('/signature/hub/api/v1/people/p-nobody', $this->bearer($token))
        ->assertNotFound()->assertExactJson(['error' => 'unknown_person', 'message' => 'No person with that sub.']);
});

it('links an app as a holder', function () {
    $app = $this->registerApp();
    $this->person();

    $this->postJson('/signature/hub/api/v1/people/p-juan/link', [], $this->bearer($this->appToken($app['app'])))
        ->assertOk()->assertExactJson(['linked' => true]);

    expect(HubHolder::query()->where('app_id', $app['app']->id)->where('personnel_key', 'p-juan')->count())->toBe(1);
});

// ── signature ────────────────────────────────────────────────────────────

it('returns signature metadata for a verified person', function () {
    $app = $this->registerApp();
    $signature = $this->signer();

    $this->getJson('/signature/hub/api/v1/people/p-juan/signature', $this->bearer($this->appToken($app['app'])))
        ->assertOk()
        ->assertJson([
            'uuid'         => $signature->uuid,
            'status'       => 'active',
            'image_sha256' => $signature->image_hash,
        ])
        ->assertJsonStructure(['uuid', 'status', 'image_sha256', 'certificate_fingerprint', 'updated_at']);
});

it('has no signature to show for an unverified claim or a revoked one', function () {
    $app = $this->registerApp();
    $token = $this->appToken($app['app']);

    $this->person();
    $this->account(status: Identity::PENDING);
    $this->specimen();

    $this->getJson('/signature/hub/api/v1/people/p-juan/signature', $this->bearer($token))
        ->assertNotFound()->assertJson(['error' => 'no_signature']);

    Identity::query()->update(['status' => Identity::VERIFIED]);
    \Kukux\DigitalSignature\Models\Signature::query()->update(['status' => 'revoked']);

    $this->getJson('/signature/hub/api/v1/people/p-juan/signature', $this->bearer($token))
        ->assertNotFound()->assertJson(['error' => 'no_signature']);
});

it('serves the image only to holder apps, with ETag and X-Image-Sha256, and audits it', function () {
    $app = $this->registerApp();
    $signature = $this->signer();
    $token = $this->appToken($app['app']);
    $bytes = Storage::disk('testing')->get($signature->image_path);
    $sha = hash('sha256', $bytes);

    $this->get('/signature/hub/api/v1/people/p-juan/signature/image', $this->bearer($token))
        ->assertForbidden()->assertExactJson([
            'error'   => 'not_linked',
            'message' => 'This app may fetch the signature only of people who signed in to it or that it linked.',
        ]);

    HubHolder::link($app['app']->id, 'p-juan');

    $response = $this->get('/signature/hub/api/v1/people/p-juan/signature/image', $this->bearer($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('ETag', "\"{$sha}\"")
        ->assertHeader('X-Image-Sha256', $sha);

    expect($response->getContent())->toBe($bytes);

    $audit = SignatureAudit::query()->where('event', SignatureAudit::HUB_IMAGE_SERVED)->sole();
    expect($audit->app)->toBe('performance')
        ->and($audit->personnel_key)->toBe('p-juan')
        ->and($audit->subject_user_id)->toBe(42)
        ->and($audit->signature_id)->toBe($signature->id)
        ->and(HubHolder::query()->first()->last_pulled_at)->not->toBeNull();
});

it('answers 304 to a matching If-None-Match', function () {
    $app = $this->registerApp();
    $signature = $this->signer();
    HubHolder::link($app['app']->id, 'p-juan');
    $sha = $signature->image_hash;

    $this->get('/signature/hub/api/v1/people/p-juan/signature/image', $this->bearer($this->appToken($app['app'])) + [
        'If-None-Match' => "W/\"{$sha}\"",
    ])->assertStatus(304)->assertHeader('ETag', "\"{$sha}\"");

    $this->get('/signature/hub/api/v1/people/p-juan/signature/image', $this->bearer($this->appToken($app['app'])) + [
        'If-None-Match' => '"'.str_repeat('0', 64).'"',
    ])->assertOk();

    expect(SignatureAudit::query()->where('event', SignatureAudit::HUB_IMAGE_SERVED)->count())->toBe(1);
});

it('serves the master from the specimen disk when one is configured', function () {
    $app = $this->registerApp();
    $signature = $this->signer();
    HubHolder::link($app['app']->id, 'p-juan');

    Storage::fake('specimens');
    config(['signature.hub.specimen_disk' => 'specimens']);
    Storage::disk('specimens')->put($signature->image_path, $master = stampablePng(10, 10));

    $this->get('/signature/hub/api/v1/people/p-juan/signature/image', $this->bearer($this->appToken($app['app'])))
        ->assertOk()
        ->assertHeader('X-Image-Sha256', hash('sha256', $master));
});

// ── certificates ─────────────────────────────────────────────────────────

it('reports certificate status: valid, revoked, unknown', function () {
    $app = $this->registerApp();
    $token = $this->appToken($app['app']);
    $this->person();
    $this->account();

    $cert = UserCertificate::create([
        'user_id' => 42, 'pfx_path' => 'certs/x.pfx', 'fingerprint' => str_repeat('ab', 32),
        'subject_dn' => 'CN=Juan dela Cruz', 'issued_at' => now(), 'expires_at' => now()->addYear(),
    ]);

    $this->getJson('/signature/hub/api/v1/certificates/'.str_repeat('AB', 32), $this->bearer($token))
        ->assertOk()->assertJson(['fingerprint' => str_repeat('ab', 32), 'status' => 'valid', 'subject' => 'CN=Juan dela Cruz']);

    $cert->update(['revoked_at' => now()]);

    $this->getJson('/signature/hub/api/v1/certificates/'.str_repeat('ab', 32), $this->bearer($token))
        ->assertOk()->assertJson(['status' => 'revoked'])->assertJsonStructure(['revoked_at']);

    $this->getJson('/signature/hub/api/v1/certificates/'.str_repeat('cd', 32), $this->bearer($token))
        ->assertOk()->assertExactJson(['fingerprint' => str_repeat('cd', 32), 'status' => 'unknown']);

    $this->getJson('/signature/hub/api/v1/certificates/xyz', $this->bearer($token))
        ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
});
