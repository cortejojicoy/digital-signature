<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Client\HubDocumentReporter;
use Kukux\DigitalSignature\Client\Jobs\ReportDocumentToHub;
use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureDeclined;
use Kukux\DigitalSignature\Events\SigningSessionCompleted;
use Kukux\DigitalSignature\Events\SigningSessionOpened;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * Client mode: telling the hub about routed documents, so the hub can verify
 * their PDFs. What goes is fingerprints and signers, never the PDF.
 */
uses(ClientTestCase::class);

beforeEach(function () {
    $this->setUpClient();

    // A routed document: frozen base, signed once by the first of two slots.
    $this->session = SigningSession::create([
        'uuid'               => (string) Str::uuid(),
        'signable_type'      => 'App\\Models\\AccomplishmentReport',
        'signable_id'        => 7,
        'template_key'       => 'unregistered-template',
        'base_document_path' => 'docs/base.pdf',
        'base_document_hash' => hash('sha256', 'base'),
    ]);

    $signer = TestUser::create(['id' => 31, 'name' => 'Ana Prepared', 'email' => 'ana@up.edu.ph']);

    $signed = Signature::create([
        'uuid'                    => (string) Str::uuid(),
        'user_id'                 => $signer->id,
        'signing_session_id'      => $this->session->id,
        'image_path'              => 'signatures/ana.png',
        'image_hash'              => str_repeat('1', 64),
        'source'                  => 'hub',
        'status'                  => 'signed',
        'signed_document_path'    => 'signed-docs/v1.pdf',
        'signed_document_hash'    => hash('sha256', 'v1'),
        'certificate_fingerprint' => str_repeat('f', 64),
        'signed_at'               => now(),
    ]);

    foreach ([['prepared_by', 1, $signer->id, $signed->id, RouteState::Signed], ['accepted_by', 2, null, null, RouteState::Blocked]] as [$slot, $seq, $user, $sig, $state]) {
        SignatureRequest::create([
            'uuid' => (string) Str::uuid(), 'signing_session_id' => $this->session->id,
            'slot_key' => $slot, 'role' => $slot, 'user_id' => $user, 'signature_id' => $sig,
            'sequence' => $seq, 'required' => true, 'state' => $state,
        ]);
    }

    $this->signed = $signed;
    $this->documentUrl = 'hub.test/signature/hub/api/v1/documents/'.$this->session->uuid;
});

it('sends the hub each version\'s fingerprint and who signed which slot', function () {
    $this->fakeHub([$this->documentUrl => Http::response(['uuid' => $this->session->uuid, 'status' => 'open', 'versions' => 2])]);

    expect(app(HubDocumentReporter::class)->report($this->session))->toBeTrue();

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PUT' || ! str_contains($request->url(), $this->documentUrl)) {
            return false;
        }

        $body = $request->data();

        return $body['status'] === 'open'
            && $body['required'] === 2
            && array_column($body['versions'], 'sha256') === [hash('sha256', 'base'), hash('sha256', 'v1')]
            && $body['slots'][0] == [
                'slot' => 'prepared_by', 'label' => 'Prepared By', 'state' => 'signed', 'signer' => 'Ana Prepared',
                'signed_at' => $this->signed->signed_at->toIso8601String(), 'version' => 1,
                'certificate_fingerprint' => str_repeat('f', 64), 'revoked' => false,
            ]
            && $body['slots'][1]['state'] === 'pending'
            && $body['slots'][1]['signer'] === null
            // Never the document, its path, or anyone's email.
            && ! str_contains(json_encode($body), 'signed-docs/')
            && ! str_contains(json_encode($body), '@');
    });
});

it('has nothing to report before the document is frozen', function () {
    Http::fake();
    $this->session->update(['base_document_hash' => null]);

    expect(app(HubDocumentReporter::class)->report($this->session))->toBeFalse();

    Http::assertNothingSent();
});

it('queues a report after every change to a routed document', function () {
    Queue::fake();

    event(new SigningSessionOpened($this->session));
    event(new DocumentSigned($this->signed));
    event(new SignatureDeclined(SignatureRequest::query()->where('slot_key', 'accepted_by')->first()));
    event(new SigningSessionCompleted($this->session));

    Queue::assertPushed(ReportDocumentToHub::class, 4);
    Queue::assertPushed(ReportDocumentToHub::class, fn (ReportDocumentToHub $job) => $job->sessionId === $this->session->id);
});

it('lets a real queue retry while the hub is down', function () {
    $this->fakeHub([$this->documentUrl => Http::response(['error' => 'unavailable'], 503)]);

    $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queued->shouldReceive('getConnectionName')->andReturn('database');

    $job = new ReportDocumentToHub($this->session->id);
    $job->setJob($queued);

    expect(fn () => $job->handle(app(HubDocumentReporter::class)))
        ->toThrow(\Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException::class);
});

it('never fails a signature over a report when the queue runs inline', function () {
    // On the sync driver the job runs inside the signing request. A hub
    // outage there is logged and left for signature:hub-sync-documents.
    $this->fakeHub([$this->documentUrl => Http::response(['error' => 'unavailable'], 503)]);

    $job = new ReportDocumentToHub($this->session->id);

    expect(fn () => $job->handle(app(HubDocumentReporter::class)))->not->toThrow(\Throwable::class);
});

it('gives up at once when the hub refuses the report, instead of retrying it', function () {
    $this->fakeHub([$this->documentUrl => Http::response(['error' => 'invalid_request', 'message' => 'bad'], 422)]);

    $job = Mockery::mock(ReportDocumentToHub::class.'[fail]', [$this->session->id]);
    $job->shouldReceive('fail')->once();

    $job->handle(app(HubDocumentReporter::class));
});

it('backfills every routed document with one command', function () {
    $this->fakeHub([$this->documentUrl => Http::response(['uuid' => $this->session->uuid, 'status' => 'open', 'versions' => 2])]);

    $this->artisan('signature:hub-sync-documents')
        ->expectsOutputToContain('1 sent, 0 failed')
        ->assertSuccessful();

    $this->artisan('signature:hub-sync-documents', ['--since' => now()->addDay()->toDateString()])
        ->expectsOutputToContain('0 sent')
        ->assertSuccessful();
});

it('reports a failure from the backfill instead of hiding it', function () {
    $this->fakeHub([$this->documentUrl => Http::response(['error' => 'invalid_request', 'message' => 'bad report'], 422)]);

    $this->artisan('signature:hub-sync-documents')
        ->expectsOutputToContain('0 sent, 1 failed')
        ->assertFailed();
});
