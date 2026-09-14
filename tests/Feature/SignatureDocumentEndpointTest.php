<?php

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The document behind a signature request.
 *
 * The queue is per-signatory, and so is the PDF behind it. These endpoints are
 * the reason the package can show a signatory what they are signing without
 * making the document publicly readable, so most of what is worth testing here
 * is who is refused: an id from somebody else's queue, a signature belonging
 * to somebody else, and a guest.
 */
describe('signature request document endpoints', function () {

    beforeEach(function () {
        Storage::fake('testing');

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

        makeUser(11, 'Juan Dela Cruz');
        makeUser(12, 'Maria Santos');
        makeUser(99, 'Uninvolved Person');

        $this->juanSig = makePrimarySignature(11);
        makePrimarySignature(12);

        $this->report = AccomplishmentReport::create([
            'prepared_by_id' => 11,
            'attested_by_id' => 12,
        ]);

        // The cryptographic pipeline is exercised elsewhere; here it only has
        // to leave a signed path behind so the session can advance.
        $manager = Mockery::mock(
            SignatureManager::class.'[embedAndFinalize]',
            [
                app(\Kukux\DigitalSignature\Services\CertificateService::class),
                app(\Kukux\DigitalSignature\Services\PdfSignerService::class),
                app(\Kukux\DigitalSignature\Security\DuplicateSignatureGuard::class),
                app(\Kukux\DigitalSignature\Security\CrlValidator::class),
                app(\Kukux\DigitalSignature\Security\DocumentIntegrity::class),
                app(\Kukux\DigitalSignature\Security\SignatureMetadataService::class),
            ],
        );
        $manager->shouldReceive('embedAndFinalize')
            ->andReturnUsing(function (Signature $sig, string $pw, ?string $source = null) {
                $out = 'signed-docs/sig-'.$sig->id.'.pdf';
                // The bytes and the recorded hash have to agree, or the chain
                // assertions below compare a real hash of the file against a
                // fabricated one and fail for reasons that say nothing about
                // the code under test.
                $body = 'signed-pdf-'.$sig->id;
                Storage::disk('testing')->put($out, $body);
                $sig->update([
                    'signed_document_path' => $out,
                    'signed_document_hash' => hash('sha256', $body),
                    'status'               => 'signed',
                    'signed_at'            => now(),
                ]);
            });

        app()->instance(SignatureManager::class, $manager);
        app()->forgetInstance(SigningSessionManager::class);

        $this->session  = app(SigningSessionManager::class)->open($this->report);
        $this->prepared = $this->session->requests->firstWhere('slot_key', 'prepared_by');
        $this->attested = $this->session->requests->firstWhere('slot_key', 'attested_by');
    });

    afterEach(fn () => Mockery::close());

    // ── Reading ──────────────────────────────────────────────────────────────

    it('describes the document and the slot to its own signatory', function () {
        $this->actingAs(TestUser::find(11))
            ->getJson("/signature/requests/{$this->prepared->id}/meta")
            ->assertOk()
            ->assertJsonPath('opened', $this->prepared->id)
            ->assertJsonPath('requests.0.slot', 'prepared_by')
            ->assertJsonPath('requests.0.blocked', false)
            // The frozen placement, so the box opens where the administrator
            // positioned the slot rather than somewhere arbitrary.
            ->assertJsonPath('requests.0.placement.x', 100)
            ->assertJsonPath('requests.0.placement.page', 1)
            ->assertJsonPath('signatures.0.id', $this->juanSig->id);
    });

    it('sends the caption and layout rules the placement preview needs', function () {
        // The box a signatory drags holds ink, a QR and a caption. Without
        // these the preview would draw only the signature, and they would be
        // aligning something other than what prints.
        $this->actingAs(TestUser::find(11))
            ->getJson("/signature/requests/{$this->prepared->id}/meta")
            ->assertOk()
            ->assertJsonPath('stamp.caption.enabled', true)
            ->assertJsonPath('stamp.caption.heightRatio', 0.38)
            ->assertJsonPath('stamp.qr.enabled', true)
            // Per signature, because the reference line is the signature's own.
            ->assertJsonPath('signatures.0.caption.2', 'Ref '.substr($this->juanSig->uuid, 0, 8));
    });

    it('stops advertising a QR when verification is switched off', function () {
        // The code would lead to a page that returns 404, so the preview must
        // not reserve space for one.
        config()->set('signature.verify.enabled', false);

        $this->actingAs(TestUser::find(11))
            ->getJson("/signature/requests/{$this->prepared->id}/meta")
            ->assertOk()
            ->assertJsonPath('stamp.qr.enabled', false);
    });

    it('tells a later signatory that someone else has to go first', function () {
        $this->actingAs(TestUser::find(12))
            ->getJson("/signature/requests/{$this->attested->id}/meta")
            ->assertOk()
            ->assertJsonPath('requests.0.blocked', true);
    });

    it('lists only this signatory’s own slots, never the other signatory’s', function () {
        // Juan is on one slot here; Maria's must not appear in his payload,
        // or the client would offer him somewhere to place a signature he is
        // not entitled to put there.
        $this->actingAs(TestUser::find(11))
            ->getJson("/signature/requests/{$this->prepared->id}/meta")
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $this->prepared->id);
    });

    it('streams the PDF to its own signatory', function () {
        $response = $this->actingAs(TestUser::find(11))
            ->get("/signature/requests/{$this->prepared->id}/document");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Never cached: the URL is not the capability, the session is, and the
        // document changes as people sign it.
        expect($response->headers->get('cache-control'))->toContain('no-store');
    });

    it('serves the running document, so a signatory sees the signatures already applied', function () {
        app(SigningSessionManager::class)->sign($this->prepared, 11);

        $running = $this->session->fresh()->current_document_path;

        expect($running)->not->toBeNull();

        $response = $this->actingAs(TestUser::find(12))
            ->get("/signature/requests/{$this->attested->id}/document");

        $response->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk('testing')->get($running));
    });

    it('serves an already-signed slot as history rather than work', function () {
        app(SigningSessionManager::class)->sign($this->prepared, 11);

        $this->actingAs(TestUser::find(11))
            ->getJson("/signature/requests/{$this->prepared->id}/meta")
            ->assertOk()
            // Still readable: the signatory's certificate is on this document
            // and they are entitled to see what they signed.
            ->assertJsonPath('readOnly', true)
            ->assertJsonPath('state', 'signed')
            ->assertJsonPath('stateLabel', 'Signed')
            // Nothing left to place, so the client renders no signing surface.
            ->assertJsonCount(0, 'requests');
    });

    it('still streams the document to a signatory who has already signed it', function () {
        app(SigningSessionManager::class)->sign($this->prepared, 11);

        $this->actingAs(TestUser::find(11))
            ->get("/signature/requests/{$this->prepared->id}/document")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('refuses to sign a slot that is already signed', function () {
        app(SigningSessionManager::class)->sign($this->prepared, 11);

        // The read-only flag is advisory; this is the rule that enforces it.
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'page' => 1, 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 30,
            ])
            ->assertStatus(422);
    });

    // ── Refusals ─────────────────────────────────────────────────────────────

    it('refuses a request from somebody else’s queue', function () {
        foreach (['meta', 'document'] as $endpoint) {
            $this->actingAs(TestUser::find(11))
                ->get("/signature/requests/{$this->attested->id}/{$endpoint}")
                ->assertStatus(403);
        }
    });

    it('refuses an uninvolved user outright', function () {
        $this->actingAs(TestUser::find(99))
            ->get("/signature/requests/{$this->prepared->id}/document")
            ->assertStatus(403);
    });

    it('refuses a guest', function () {
        $this->get("/signature/requests/{$this->prepared->id}/document")
            ->assertStatus(403);
    });

    // ── Signing ──────────────────────────────────────────────────────────────

    it('signs at the placement the signatory chose', function () {
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'page' => 1, 'x' => 123.5, 'y' => 456.5, 'width' => 180, 'height' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'signed')
            ->assertJsonPath('outstanding', 1);

        $signed = $this->prepared->fresh();

        expect($signed->state)->toBe(RouteState::Signed)
            ->and($signed->x)->toBe(123.5)
            ->and($signed->y)->toBe(456.5)
            ->and($signed->width)->toBe(180.0);
    });

    it('signs at the frozen placement when none is sent', function () {
        // The keyboard path and the old one-click behaviour both land here.
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [])
            ->assertOk();

        expect($this->prepared->fresh()->x)->toBe(100.0);
    });

    it('will not let a signatory sign into somebody else’s request', function () {
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->attested->id}/sign", [
                'page' => 1, 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 30,
            ])
            ->assertStatus(403);

        expect($this->attested->fresh()->state)->not->toBe(RouteState::Signed)
            // Crucially the placement is not written either: a refused signature
            // must not leave the next signatory's slot moved.
            ->and($this->attested->fresh()->x)->toBe(300.0);
    });

    it('refuses a signature belonging to another user', function () {
        $mariaSig = Signature::where('user_id', 12)->first();

        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'page' => 1, 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 30,
                'signature_id' => $mariaSig->id,
            ])
            ->assertStatus(403);

        expect($this->prepared->fresh()->state)->not->toBe(RouteState::Signed);
    });

    it('still enforces sequencing', function () {
        $this->actingAs(TestUser::find(12))
            ->postJson("/signature/requests/{$this->attested->id}/sign", [
                'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error',
                'This document is signed in order — "prepared_by" must be signed before "attested_by".',
            );
    });

    // ── Several slots at once ────────────────────────────────────────────────
    //
    // The same person being two signatories on one form is routine — "Prepared
    // by" and "Noted by" on an accomplishment report, say. Each signature is
    // still its own record, chained to the one before it; the batch only saves
    // the signatory from opening the document twice.

    it('places and signs several of its own slots in one call', function () {
        // Re-route the report so Juan holds both slots.
        $this->report->update(['attested_by_id' => 11]);
        app(SigningSessionManager::class)->refreshAssignments($this->session);

        $prepared = $this->prepared->fresh();
        $attested = $this->attested->fresh();

        expect($attested->user_id)->toBe(11);

        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $attested->id, 'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50],
                    ['request_id' => $prepared->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'signed')
            ->assertJsonCount(2, 'signed')
            // Applied in sequence order regardless of payload order — each
            // signature chains onto the previous one's document.
            ->assertJsonPath('signed.0.slot', 'prepared_by')
            ->assertJsonPath('signed.1.slot', 'attested_by')
            ->assertJsonPath('outstanding', 0);

        expect($prepared->fresh()->state)->toBe(RouteState::Signed)
            ->and($attested->fresh()->state)->toBe(RouteState::Signed);
    });

    it('chains the second signature onto the first one’s document', function () {
        $this->report->update(['attested_by_id' => 11]);
        app(SigningSessionManager::class)->refreshAssignments($this->session);

        $attested = $this->attested->fresh();

        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                    ['request_id' => $attested->id,       'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50],
                ],
            ])
            ->assertOk();

        $first  = Signature::where('slot_key', 'prepared_by')->whereNotNull('signing_session_id')->first();
        $second = Signature::where('slot_key', 'attested_by')->whereNotNull('signing_session_id')->first();

        // The chain is the point: without it the second stamp would be applied
        // to a document that does not contain the first.
        expect($second->parent_signature_id)->toBe($first->id)
            ->and($second->document_hash)->toBe($first->signed_document_hash);
    });

    it('refuses to batch a slot belonging to somebody else', function () {
        // Juan holds prepared_by; attested_by is Maria's. Holding one request
        // id must not become a licence to sign the other.
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                    ['request_id' => $this->attested->id, 'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50],
                ],
            ])
            ->assertStatus(403);

        // Nothing was applied: the batch is resolved in full before any of it
        // is signed, so an unauthorised entry stops the whole call.
        expect($this->prepared->fresh()->state)->not->toBe(RouteState::Signed);
    });

    it('refuses a slot from a different document', function () {
        $other = AccomplishmentReport::create(['prepared_by_id' => 11, 'attested_by_id' => 12]);
        $otherSession = app(SigningSessionManager::class)->open($other);
        $otherRequest = $otherSession->requests->firstWhere('slot_key', 'prepared_by');

        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $otherRequest->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                ],
            ])
            ->assertStatus(403);
    });

    it('stamps one signature in several places on the same slot', function () {
        // A form asks the same person for the same signature more than once —
        // the signature block, then again under a certificate, then on an
        // acceptance clause. That is one act of signing with several
        // appearances, not several signatures.
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 90,  'width' => 160, 'height' => 50],
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 300, 'width' => 160, 'height' => 50],
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 500, 'width' => 160, 'height' => 50],
                ],
            ])
            ->assertOk()
            // One signature...
            ->assertJsonCount(1, 'signed')
            // ...drawn three times.
            ->assertJsonPath('signed.0.stamps', 3);

        $signature = Signature::where('slot_key', 'prepared_by')
            ->whereNotNull('signing_session_id')
            ->firstOrFail();

        // One row, one PKCS#7 block, one link in the chain — three placements.
        expect($signature->positions)->toHaveCount(3)
            ->and($signature->positions->pluck('y')->all())->toBe([90.0, 300.0, 500.0])
            ->and(Signature::where('slot_key', 'prepared_by')
                ->whereNotNull('signing_session_id')->count())->toBe(1);
    });

    it('needs coordinates for a repeated placement', function () {
        // The first placement may be omitted to mean "use the frozen slot";
        // a second one has no such default to fall back on.
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                    ['request_id' => $this->prepared->id],
                ],
            ])
            ->assertStatus(422);
    });

    it('reports how far a partly-successful batch got', function () {
        // Juan takes both slots, but the session is sequential and the second
        // one is made unsignable by declining it first.
        $this->report->update(['attested_by_id' => 11]);
        app(SigningSessionManager::class)->refreshAssignments($this->session);

        $attested = $this->attested->fresh();
        app(SigningSessionManager::class)->decline($attested, 11, 'not mine to sign');

        $response = $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'placements' => [
                    ['request_id' => $this->prepared->id, 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
                    ['request_id' => $attested->id,       'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'partial')
            ->assertJsonCount(1, 'signed')
            ->assertJsonPath('signed.0.slot', 'prepared_by')
            ->assertJsonPath('failed_on.slot', 'attested_by');

        // A signature that happened stays happened — there is no honest way to
        // un-sign a PDF somebody already put a certificate on.
        expect($this->prepared->fresh()->state)->toBe(RouteState::Signed);
    });

    it('rejects a placement that is not a usable rectangle', function () {
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'page' => 1, 'x' => -5, 'y' => 10, 'width' => 0, 'height' => 30,
            ])
            ->assertStatus(422);

        expect($this->prepared->fresh()->state)->not->toBe(RouteState::Signed);
    });
});
