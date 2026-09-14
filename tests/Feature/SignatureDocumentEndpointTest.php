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
                Storage::disk('testing')->put($out, 'signed');
                $sig->update([
                    'signed_document_path' => $out,
                    'signed_document_hash' => hash('sha256', 'signed-'.$sig->id),
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
            ->assertJsonPath('request.slot', 'prepared_by')
            ->assertJsonPath('request.blocked', false)
            // The frozen placement, so the box opens where the administrator
            // positioned the slot rather than somewhere arbitrary.
            ->assertJsonPath('request.placement.x', 100)
            ->assertJsonPath('request.placement.page', 1)
            ->assertJsonPath('signatures.0.id', $this->juanSig->id);
    });

    it('tells a later signatory that someone else has to go first', function () {
        $this->actingAs(TestUser::find(12))
            ->getJson("/signature/requests/{$this->attested->id}/meta")
            ->assertOk()
            ->assertJsonPath('request.blocked', true);
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

    it('rejects a placement that is not a usable rectangle', function () {
        $this->actingAs(TestUser::find(11))
            ->postJson("/signature/requests/{$this->prepared->id}/sign", [
                'page' => 1, 'x' => -5, 'y' => 10, 'width' => 0, 'height' => 30,
            ])
            ->assertStatus(422);

        expect($this->prepared->fresh()->state)->not->toBe(RouteState::Signed);
    });
});
