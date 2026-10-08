<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Client\HubSigning;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner as DeferredPdfSignerContract;
use Kukux\DigitalSignature\Drivers\PdfSigners\DeferredPdfSigner;
use Kukux\DigitalSignature\Hub\Cms\CmsSigner;
use Kukux\DigitalSignature\Hub\Cms\SignedDataReader;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\CertificateService;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;
use Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys;

/**
 * Hash-only signing through the hub, from the drawer's sign endpoint: stamp
 * and reserve here, ask the hub, wait on the signer's computer through the
 * existing approval overlay, then inject the hub's CMS and finish as
 * standalone does. No certificate is ever made here.
 */
uses(ClientTestCase::class);

const HUB_REQUEST = '33333333-cccc-4ccc-8ccc-333333333333';
const HUB_JOB = '44444444-dddd-4ddd-8ddd-444444444444';

beforeEach(function () {
    $this->setUpClient();

    registerRoutedTemplate('accomplishment-report', [
        'prepared_by' => ['signatory' => 'preparedBy', 'order' => 1, 'required' => true],
        'attested_by' => ['signatory' => 'attestedBy', 'order' => 2, 'required' => true],
    ], ['renderer' => \Kukux\DigitalSignature\Tests\Support\StubPdfRenderer::class]);

    foreach (['prepared_by', 'attested_by'] as $i => $slot) {
        PdfTemplateSlot::updateOrCreate(
            ['template_key' => 'accomplishment-report', 'slot_key' => $slot],
            ['page' => 1, 'x' => 100.0 + ($i * 200), 'y' => 90.0, 'width' => 160.0, 'height' => 50.0],
        );
    }

    $this->juan = $this->linkedUser(11, 'p-juan', 'Juan Dela Cruz');
    $this->maria = $this->linkedUser(12, 'p-maria', 'Maria Santos');
    $this->juanMirror = $this->mirror(11);
    $this->mirror(12, stampablePng(90, 30), 'eeeeeeee-5555-4555-8555-555555555555');

    $report = AccomplishmentReport::create(['prepared_by_id' => 11, 'attested_by_id' => 12]);

    $this->session = app(SigningSessionManager::class)->open($report);
    $this->prepared = $this->session->requests->firstWhere('slot_key', 'prepared_by');

    // Nothing in client mode may touch local certificates.
    $this->app->instance(CertificateService::class, Mockery::mock(CertificateService::class, function ($mock) {
        $mock->shouldNotReceive('getOrCreate');
        $mock->shouldNotReceive('load');
    }));

    $this->hubState = ['status' => 'pending', 'cms' => null];
});

afterEach(fn () => Mockery::close());

/** The hub's sign-request endpoints, answering from $test->hubState. */
function fakeSignRequests($test, ?Closure $create = null): void
{
    $test->fakeHub([
        'hub.test/signature/hub/api/v1/sign-requests/*' => function () use ($test) {
            return Http::response(array_filter([
                'id'                      => HUB_REQUEST,
                'status'                  => $test->hubState['status'],
                'cms'                     => $test->hubState['cms'],
                'certificate_fingerprint' => $test->hubState['status'] === 'signed' ? str_repeat('AB:', 31).'AB' : null,
                'signed_at'               => $test->hubState['status'] === 'signed' ? now()->toIso8601String() : null,
            ]));
        },
        'hub.test/signature/hub/api/v1/sign-requests' => $create ?? fn () => Http::response([
            'id'            => HUB_REQUEST,
            'status'        => 'pending',
            'approval_link' => 'kukuxsign://job/'.HUB_JOB.'?t=tok&s=hub',
            'job_uuid'      => HUB_JOB,
            'expires_at'    => now()->addMinutes(5)->toIso8601String(),
        ], 202),
    ]);
}

function signPrepared($test)
{
    return $test->postJson(route('signature.request.sign', ['signatureRequest' => $test->prepared->id]), [
        'page' => 1, 'x' => 120, 'y' => 95, 'width' => 150, 'height' => 45,
    ]);
}

describe('signing through the hub', function () {

    it('stamps, asks the hub, and hands the browser the agent approval', function () {
        fakeSignRequests($this);
        $this->actingAs($this->juan);

        $response = signPrepared($this)->assertStatus(428);

        expect($response->json('agent_approval.link'))->toBe('kukuxsign://job/'.HUB_JOB.'?t=tok&s=hub')
            ->and($response->json('agent_approval.job'))->toBe(HUB_JOB)
            ->and($response->json('agent_approval.status_url'))->toBe(route('signature.agent.web.job', HUB_JOB))
            ->and($response->json('agent_approval.skip_url'))->toBeNull();

        $pending = HubPendingSign::query()->sole();
        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sign-requests'));

        expect($pending->status)->toBe('pending')
            ->and($pending->signature_request_id)->toBe($this->prepared->id)
            ->and($request['sub'])->toBe('p-juan')
            ->and($request['document_hash'])->toBe($pending->document_hash)
            ->and($request['specimen_hash'])->toBe($this->juanMirror->hub_image_hash)
            ->and($request['slot'])->toBe('prepared_by')
            ->and($request['idempotency_key'])->toBe($pending->idempotency_key)
            ->and($this->deferred->prepared[0]['imagePath'])->toBe($this->juanMirror->image_path)
            ->and($this->deferred->prepared[0]['position'])->toMatchArray(['page' => 1, 'x' => 120.0, 'width' => 150.0])
            // The placement is saved; no document signature row yet.
            ->and($this->prepared->fresh()->x)->toEqual(120.0)
            ->and(Signature::query()->whereNotNull('signable_id')->count())->toBe(0);
    });

    it('reports the hub job to the overlay, then finishes once the hub has signed', function () {
        fakeSignRequests($this);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(428);

        $this->getJson(route('signature.agent.web.job', HUB_JOB))
            ->assertOk()
            ->assertJson(['status' => 'pending', 'device' => null]);

        $this->hubState = ['status' => 'signed', 'cms' => base64_encode('the-cms-der')];

        $this->getJson(route('signature.agent.web.job', HUB_JOB))
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        // The overlay retries the same call.
        signPrepared($this)->assertOk()->assertJson(['status' => 'signed']);

        $signature = Signature::query()->whereNotNull('signable_id')->sole();
        $pending = HubPendingSign::query()->sole();

        expect($signature->status)->toBe('signed')
            ->and($signature->source)->toBe('hub')
            ->and($signature->certificate_password)->toBeNull()
            ->and($signature->certificate_fingerprint)->toBe(str_repeat('ab', 32))
            ->and($signature->signed_document_hash)->toBe(hash('sha256', Storage::disk('testing')->get($signature->signed_document_path)))
            ->and($this->deferred->injected)->toHaveCount(1)
            ->and($this->deferred->injected[0]['cms'])->toBe('the-cms-der')
            ->and($pending->status)->toBe('done')
            ->and($pending->payload['signature_id'])->toBe($signature->id)
            ->and($this->prepared->fresh()->state)->toBe(RouteState::Signed)
            ->and($this->session->fresh()->current_document_path)->toBe($signature->signed_document_path);
        Storage::disk('testing')->assertMissing($pending->prepared_path);
    });

    it('finishes from the webhook\'s CMS without asking the hub again', function () {
        fakeSignRequests($this);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(428);

        $this->webhook('sign_request.completed', ['id' => HUB_REQUEST, 'sub' => 'p-juan', 'status' => 'signed', 'cms' => base64_encode('webhook-cms')])
            ->assertOk();

        signPrepared($this)->assertOk();

        expect($this->deferred->injected[0]['cms'])->toBe('webhook-cms');
    });

    it('re-pulls, re-stamps and resubmits once on 409 specimen_changed', function () {
        $new = stampablePng(160, 60);
        $posts = 0;

        fakeSignRequests($this, function (Request $request) use (&$posts, $new) {
            return ++$posts === 1
                ? Http::response(['error' => 'specimen_changed', 'message' => 'Changed.', 'current_specimen_hash' => hash('sha256', $new)], 409)
                : Http::response(['id' => HUB_REQUEST, 'status' => 'pending', 'approval_link' => 'kukuxsign://job/'.HUB_JOB, 'job_uuid' => HUB_JOB, 'expires_at' => now()->addMinutes(5)->toIso8601String()], 202);
        });
        Http::fake($this->hubSignatureRoutes('p-juan', $new, 'ffffffff-6666-4666-8666-666666666666'));
        $this->actingAs($this->juan);

        signPrepared($this)->assertStatus(428);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sign-requests'))->values();

        expect($posts)->toBe(2)
            ->and($sent[1]['specimen_hash'])->toBe(hash('sha256', $new))
            ->and($sent[1]['idempotency_key'])->not->toBe($sent[0]['idempotency_key'])
            ->and($this->deferred->prepared)->toHaveCount(2)
            ->and($this->juanMirror->fresh()->hub_image_hash)->toBe(hash('sha256', $new))
            ->and(HubPendingSign::query()->pluck('status')->all())->toBe(['failed', 'pending']);
    });

    it('stops after one resubmit when the specimen changes again', function () {
        $new = stampablePng(160, 60);
        fakeSignRequests($this, fn () => Http::response(['error' => 'specimen_changed', 'message' => 'Changed.'], 409));
        Http::fake($this->hubSignatureRoutes('p-juan', $new, 'ffffffff-6666-4666-8666-666666666666'));
        $this->actingAs($this->juan);

        signPrepared($this)->assertStatus(422)->assertJsonPath('error', 'Your signature changed at UPLB Signature while this was being signed. Try again.');

        expect($this->deferred->prepared)->toHaveCount(2);
    });

    it('keeps the placement and the request unsent when the hub is down', function () {
        $this->fakeHub([
            'hub.test/signature/hub/api/v1/sign-requests' => fn () => throw new ConnectionException('down'),
        ]);
        $this->actingAs($this->juan);

        signPrepared($this)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Signing is unavailable. Your placement is saved.');

        expect(HubPendingSign::query()->sole()->status)->toBe('unsent')
            ->and($this->prepared->fresh()->x)->toEqual(120.0)
            ->and($this->prepared->fresh()->state)->not->toBe(RouteState::Signed);
    });

    it('says why when the hub refuses', function () {
        fakeSignRequests($this, fn () => Http::response(['error' => 'not_verified', 'message' => 'Pending verification.'], 422));
        $this->actingAs($this->juan);

        signPrepared($this)->assertStatus(422)->assertJsonPath('error', 'UPLB Signature has not verified your identity yet, so it cannot sign for you.');

        expect(HubPendingSign::query()->sole()->status)->toBe('refused');
    });

    it('tells the overlay when the signer declined', function () {
        fakeSignRequests($this);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(428);

        $this->hubState = ['status' => 'declined', 'cms' => null];

        $this->getJson(route('signature.agent.web.job', HUB_JOB))->assertJson(['status' => 'rejected']);
        $this->postJson(route('signature.agent.web.skip'))->assertForbidden();
    });

    it('answers 404 for a job that is not this user\'s', function () {
        fakeSignRequests($this);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(428);

        $this->actingAs($this->maria)->getJson(route('signature.agent.web.job', HUB_JOB))->assertNotFound();
    });

    it('refuses a user who never signed in through the hub', function () {
        \Kukux\DigitalSignature\Models\HubAccount::query()->where('user_id', 11)->delete();
        Http::fake();
        $this->actingAs($this->juan);

        signPrepared($this)->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'not linked to UPLB Signature'));
        Http::assertNothingSent();
    });
});

describe('signature:hub-retry', function () {

    it('sends unsent requests once the hub is back, and refreshes stale pending ones', function () {
        $this->fakeHub(['hub.test/signature/hub/api/v1/sign-requests' => fn () => throw new ConnectionException('down')]);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(422);

        app(\Kukux\DigitalSignature\Client\HubClient::class)->reset();
        Http::swap(new \Illuminate\Http\Client\Factory);
        fakeSignRequests($this);

        $this->artisan('signature:hub-retry')->expectsOutputToContain('1 sent')->assertSuccessful();

        $pending = HubPendingSign::query()->sole();
        expect($pending->status)->toBe('pending')->and($pending->attempts)->toBe(2);

        // Stale pending: the hub signed and the webhook was missed.
        $this->hubState = ['status' => 'signed', 'cms' => base64_encode('late')];
        $this->travel(5)->minutes();

        $this->artisan('signature:hub-retry')->expectsOutputToContain('1 pending requests changed status')->assertSuccessful();

        expect($pending->fresh()->status)->toBe('signed');
    });
});

describe('with the real DeferredPdfSigner and a CMS from CmsSigner', function () {

    it('produces a PDF whose embedded CMS verifies over its /ByteRange', function () {
        $this->app->forgetInstance(DeferredPdfSignerContract::class);
        $this->app->bind(DeferredPdfSignerContract::class, DeferredPdfSigner::class);

        // A real PDF to stamp, in place of the stub render.
        Storage::disk('testing')->put($this->session->base_document_path, minimalPdf());
        $this->session->update(['base_document_hash' => hash('sha256', minimalPdf())]);

        $identity = CmsTestKeys::rsa();

        fakeSignRequests($this);
        $this->actingAs($this->juan);
        signPrepared($this)->assertStatus(428);

        // The hub signs exactly the digest the app sent.
        $digest = HubPendingSign::query()->sole()->document_hash;
        $this->hubState = [
            'status' => 'signed',
            'cms'    => base64_encode(app(CmsSigner::class)->signDigest($digest, $identity['cert'], $identity['key'], $identity['chain'])),
        ];

        signPrepared($this)->assertOk();

        $pdf = Storage::disk('testing')->get(Signature::query()->whereNotNull('signable_id')->sole()->signed_document_path);
        $placeholder = DeferredPdfSigner::locatePlaceholder($pdf);
        [$a, $b, $c, $d] = $placeholder['byteRange'];
        $covered = substr($pdf, $a, $b).substr($pdf, $c, $d);

        $hex = rtrim(substr($pdf, $b + 1, $placeholder['length']), '0');
        $hex .= strlen($hex) % 2 ? '0' : '';
        $offset = 0;
        $cms = Element::readAt(hex2bin($hex)."\0\0\0\0", $offset)->encoded;
        $reader = SignedDataReader::parse($cms);

        expect(hash('sha256', $covered))->toBe($digest)
            ->and($reader->messageDigest())->toBe(hash('sha256', $covered, true))
            ->and($reader->verifiesWithSignerCertificate())->toBeTrue();
    });
});

describe('the drag tray', function () {

    it('lists the mirror, and links to the hub when there is none', function () {
        $this->actingAs($this->juan);

        $meta = $this->getJson(route('signature.request.meta', ['signatureRequest' => $this->prepared->id]))->assertOk();

        expect($meta->json('signatures'))->toHaveCount(1)
            ->and($meta->json('signatures.0.source'))->toBe('hub')
            ->and($meta->json('hub.libraryUrl'))->toStartWith('https://hub.test/')
            ->and($meta->json('agent.required'))->toBeFalse();

        $this->juanMirror->update(['status' => 'revoked']);
        Http::fake(['hub.test/*' => Http::response(['error' => 'no_signature', 'message' => 'None.'], 404)]);

        expect($this->getJson(route('signature.request.meta', ['signatureRequest' => $this->prepared->id]))->json('signatures'))->toBe([]);
    });
});
