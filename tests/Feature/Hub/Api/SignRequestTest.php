<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\HubSignRequest;
use Kukux\DigitalSignature\Models\HubWebhook;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;

uses(HubApiEnvironment::class);

beforeEach(function () {
    $this->setUpHubApi();
    Http::fake(['*' => Http::response('', 204)]);
});

/*
 * Sign requests: an app sends a hash, the person approves on their computer,
 * the hub returns a CMS (docs/hub/contracts.md §2.2, §4).
 */

function signRequestBody(Signature $specimen, array $overrides = []): array
{
    return array_merge([
        'sub'             => 'p-juan',
        'document_hash'   => hash('sha256', 'accomplishment-report-v1'),
        'specimen_hash'   => $specimen->image_hash,
        'title'           => 'Accomplishment Report Q3',
        'slot'            => 'Prepared by',
        'capacity'        => 'Assistant Professor',
        'idempotency_key' => 'ar-2026-q3-prepared-by',
    ], $overrides);
}

it('signs a hash end to end: request, agent approval, CMS, audit and webhook', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $agent = $this->pairedAgent();
    $token = $this->appToken($app['app']);
    $body = signRequestBody($specimen);

    $response = $this->postJson('/signature/hub/api/v1/sign-requests', $body, $this->bearer($token))
        ->assertStatus(202)
        ->assertJson(['status' => 'pending'])
        ->assertJsonStructure(['id', 'status', 'approval_link', 'job_uuid', 'expires_at']);

    $job = AgentJob::query()->where('uuid', $response->json('job_uuid'))->sole();

    expect($response->json('approval_link'))->toStartWith('kukuxsign://job/'.$job->uuid.'?t=')
        ->and($job->requesting_app)->toBe('Performance')
        ->and($job->title)->toBe('Prepared by · Accomplishment Report Q3')
        ->and($job->payload_hash)->toBe($body['document_hash'])
        ->and($job->user_id)->toBe(42)
        ->and(app(AgentJobService::class)->payload($job)['requesting_app'])->toBe(['name' => 'Performance']);

    $requested = SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGN_REQUESTED)->sole();
    expect($requested->app)->toBe('performance')->and($requested->personnel_key)->toBe('p-juan');

    // The person approves on their computer (Touch ID).
    $this->agentApproves($agent, $response->json('approval_link'));

    $request = HubSignRequest::query()->sole();
    $cert = UserCertificate::query()->where('user_id', 42)->sole();

    expect($request->status)->toBe('signed')
        ->and(base64_decode($request->cms))->toBe("\x30\x80FAKE-CMS:".$body['document_hash'])
        ->and($request->certificate_fingerprint)->toBe($cert->fingerprint)
        ->and($request->signed_at)->not->toBeNull()
        ->and($this->digestSigner->calls)->toHaveCount(1)
        ->and($this->digestSigner->calls[0]['digest'])->toBe($body['document_hash'])
        ->and($this->digestSigner->calls[0]['certificate'])->toContain('BEGIN CERTIFICATE')
        ->and($job->refresh()->consumed_at)->not->toBeNull();

    $signed = SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGNED)->sole();
    expect($signed->app)->toBe('performance')
        ->and($signed->personnel_key)->toBe('p-juan')
        ->and($signed->device_id)->toBe($agent['device']->id)
        ->and($signed->context)->toMatchArray([
            'title'         => 'Accomplishment Report Q3',
            'slot'          => 'Prepared by',
            'capacity'      => 'Assistant Professor',
            'document_hash' => $body['document_hash'],
            'specimen_hash' => $specimen->image_hash,
            'device_id'     => $agent['device']->id,
            'purpose'       => 'sign_receipt',
            'user_presence' => 'Secure Enclave',
        ]);

    // The app polls…
    $this->getJson("/signature/hub/api/v1/sign-requests/{$response->json('id')}", $this->bearer($token))
        ->assertOk()
        ->assertExactJson([
            'id'                      => $response->json('id'),
            'status'                  => 'signed',
            'cms'                     => $request->cms,
            'certificate_fingerprint' => $cert->fingerprint,
            'signed_at'               => $request->signed_at->toIso8601String(),
        ]);

    // …and gets told: one outbox row, delivered, to the requesting app only.
    $webhook = HubWebhook::query()->sole();
    expect($webhook->event)->toBe('sign_request.completed')
        ->and($webhook->app_id)->toBe($app['app']->id)
        ->and($webhook->payload)->toBe(['id' => $request->uuid, 'sub' => 'p-juan', 'status' => 'signed', 'cms' => $request->cms])
        ->and($webhook->delivered_at)->not->toBeNull();

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://performance.uplb.test/signature/hub/webhook'
        && $r->header('X-Signature-Hub-Event')[0] === 'sign_request.completed');
});

it('refuses with 409 specimen_changed and the current hash when the app stamped an old image', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();

    $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen, [
        'specimen_hash' => str_repeat('0', 64),
    ]), $this->bearer($this->appToken($app['app'])))
        ->assertStatus(409)
        ->assertExactJson([
            'error'                 => 'specimen_changed',
            'message'               => 'The signature image changed at the hub. Re-pull it, re-stamp and resubmit.',
            'current_specimen_hash' => $specimen->image_hash,
        ]);

    expect(HubSignRequest::count())->toBe(0)->and(AgentJob::count())->toBe(0);

    $refused = SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGN_REFUSED)->sole();
    expect($refused->app)->toBe('performance')->and($refused->context['reason'])->toBe('specimen_changed');
});

it('refuses people who cannot sign', function (Closure $arrange, string $error) {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $arrange($this, $specimen);

    $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($app['app'])))
        ->assertStatus(422)
        ->assertJson(['error' => $error]);

    expect(AgentJob::count())->toBe(0)
        ->and(SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGN_REFUSED)->sole()->context['reason'])->toBe($error);
})->with([
    'unverified claim' => [fn ($t) => Identity::query()->update(['status' => Identity::PENDING]), 'not_verified'],
    'unknown person'   => [fn ($t) => $t->directory->people = [], 'unknown_person'],
    'separated'        => [fn ($t) => $t->person('p-juan', ['active' => false]), 'separated'],
    'no signature'     => [fn ($t, $s) => $s->update(['status' => 'revoked']), 'no_signature'],
    'revoked cert'     => [fn ($t) => UserCertificate::create([
        'user_id' => 42, 'pfx_path' => 'certs/old.pfx', 'fingerprint' => str_repeat('ee', 32),
        'issued_at' => now(), 'expires_at' => now()->addYear(), 'revoked_at' => now(),
    ]), 'certificate_revoked'],
]);

it('returns the same request for the same idempotency key', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $token = $this->appToken($app['app']);

    $first = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($token))
        ->assertStatus(202);

    $again = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($token))
        ->assertOk();

    expect($again->json('id'))->toBe($first->json('id'))
        ->and($again->json('approval_link'))->toBe($first->json('approval_link'))
        ->and($again->json('job_uuid'))->toBe($first->json('job_uuid'))
        ->and(HubSignRequest::count())->toBe(1)
        ->and(AgentJob::count())->toBe(1);

    // Same key, different document: a client bug, not a retry.
    $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen, [
        'document_hash' => hash('sha256', 'another'),
    ]), $this->bearer($token))->assertStatus(409)->assertJson(['error' => 'idempotency_conflict']);

    // Keys are per app.
    $amp = $this->registerApp('amp');
    $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($amp['app'])))
        ->assertStatus(202);
});

it('accepts the idempotency key as a header', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $body = signRequestBody($specimen);
    unset($body['idempotency_key']);

    $this->postJson('/signature/hub/api/v1/sign-requests', $body, $this->bearer($this->appToken($app['app'])) + [
        'Idempotency-Key' => 'header-key-123',
    ])->assertStatus(202);

    expect(HubSignRequest::query()->sole()->idempotency_key)->toBe('header-key-123');
});

it('validates the request body strictly', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $token = $this->appToken($app['app']);

    foreach ([
        ['document_hash' => 'not-a-hash'],
        ['specimen_hash' => str_repeat('g', 64)],
        ['title' => ''],
        ['idempotency_key' => 'short'],
        ['idempotency_key' => 'has spaces in it'],
        ['slot' => str_repeat('x', 129)],
    ] as $bad) {
        $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen, $bad), $this->bearer($token))
            ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
    }

    expect(HubSignRequest::count())->toBe(0);
});

it('hides one app\'s requests from another', function () {
    $app = $this->registerApp();
    $amp = $this->registerApp('amp');
    $specimen = $this->signer();

    $id = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($app['app'])))
        ->json('id');

    $this->getJson("/signature/hub/api/v1/sign-requests/{$id}", $this->bearer($this->appToken($amp['app'])))
        ->assertNotFound()->assertJson(['error' => 'unknown_request']);
});

it('records a decline and tells the app', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $agent = $this->pairedAgent();
    $response = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($app['app'])));

    app(AgentJobService::class)->reject($agent['device'], $response->json('job_uuid'), 'declined');

    expect(HubSignRequest::query()->sole())->status->toBe('declined')->refusal_reason->toBe('declined')
        ->and(HubWebhook::query()->sole()->payload)->toBe([
            'id' => $response->json('id'), 'sub' => 'p-juan', 'status' => 'declined', 'refusal_reason' => 'declined',
        ])
        ->and(SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGN_DECLINED)->count())->toBe(1)
        ->and($this->digestSigner->calls)->toBe([]);
});

it('expires a request nobody approved', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $token = $this->appToken($app['app']);
    $id = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($token))->json('id');

    $this->travel(301)->seconds();

    $this->artisan('signature:hub-webhooks')->assertSuccessful();

    expect(HubSignRequest::query()->sole()->status)->toBe('expired')
        ->and(AgentJob::query()->sole()->status)->toBe('expired')
        ->and(HubWebhook::query()->sole()->payload['status'])->toBe('expired');

    $this->getJson("/signature/hub/api/v1/sign-requests/{$id}", $this->bearer($this->appToken($app['app'])))
        ->assertOk()->assertExactJson(['id' => $id, 'status' => 'expired']);
});

it('refuses at approval when the signature was revoked while the request waited', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $agent = $this->pairedAgent();
    $response = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($app['app'])));

    $specimen->update(['status' => 'revoked']);

    $this->agentApproves($agent, $response->json('approval_link'));

    expect(HubSignRequest::query()->sole())->status->toBe('refused')->refusal_reason->toBe('no_signature')->cms->toBeNull()
        ->and($this->digestSigner->calls)->toBe([])
        ->and(HubWebhook::query()->sole()->payload)->toMatchArray(['status' => 'refused', 'refusal_reason' => 'no_signature']);
});

it('marks the request failed when the CMS signer throws', function () {
    $app = $this->registerApp();
    $specimen = $this->signer();
    $agent = $this->pairedAgent();
    $this->digestSigner->failWith = new RuntimeException('TSA unreachable');
    $response = $this->postJson('/signature/hub/api/v1/sign-requests', signRequestBody($specimen), $this->bearer($this->appToken($app['app'])));

    $this->agentApproves($agent, $response->json('approval_link'));

    expect(HubSignRequest::query()->sole())->status->toBe('failed')->refusal_reason->toBe('signing_failed')
        ->and(AgentJob::query()->sole()->status)->toBe('completed');
});
