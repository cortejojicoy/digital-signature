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
 * The inbox links a signatory to the signer page with ?request=<id>. Finalizing
 * with that id must go through the session — same sequencing, same ownership
 * rules, same running document — rather than the free-form signing path.
 */
describe('signer finalize with a session request', function () {

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
        $this->juanSig = makePrimarySignature(11);
        makePrimarySignature(12);

        $this->report = AccomplishmentReport::create([
            'prepared_by_id' => 11,
            'attested_by_id' => 12,
        ]);

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

        $this->session = app(SigningSessionManager::class)->open($this->report);
        $this->prepared = $this->session->requests->firstWhere('slot_key', 'prepared_by');
        $this->attested = $this->session->requests->firstWhere('slot_key', 'attested_by');
    });

    afterEach(fn () => Mockery::close());

    $url = fn ($uuid) => "/signature/pdf-templates/accomplishment-report/sign/{$uuid}/finalize";

    it('signs the slot and reports what is still outstanding', function () use ($url) {
        $response = $this->actingAs(TestUser::find(11))->postJson($url($this->juanSig->uuid), [
            'placements'           => [['slot' => 'prepared_by', 'page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50]],
            'signature_request_id' => $this->prepared->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'signed')
            ->assertJsonPath('session.status', 'open')
            ->assertJsonPath('session.outstanding.0.slot', 'attested_by');

        expect($this->prepared->fresh()->state)->toBe(RouteState::Signed);
    });

    it('refuses a request assigned to somebody else', function () use ($url) {
        // Juan trying to sign Maria's slot by passing her request id.
        $this->actingAs(TestUser::find(11))
            ->postJson($url($this->juanSig->uuid), [
                'placements'           => [['slot' => 'attested_by', 'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50]],
                'signature_request_id' => $this->attested->id,
            ])
            ->assertStatus(403);

        expect($this->attested->fresh()->state)->not->toBe(RouteState::Signed);
    });

    it('enforces sequencing through the endpoint too', function () use ($url) {
        $mariaSig = Signature::where('user_id', 12)->first();

        $this->actingAs(TestUser::find(12))
            ->postJson($url($mariaSig->uuid), [
                'placements'           => [['slot' => 'attested_by', 'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50]],
                'signature_request_id' => $this->attested->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This document is signed in order — "prepared_by" must be signed before "attested_by".');
    });

    it('lets the signer nudge their own slot but not retarget another', function () use ($url) {
        $this->actingAs(TestUser::find(11))->postJson($url($this->juanSig->uuid), [
            'placements' => [
                ['slot' => 'prepared_by', 'page' => 1, 'x' => 111, 'y' => 91, 'width' => 160, 'height' => 50],
                ['slot' => 'attested_by', 'page' => 1, 'x' => 999, 'y' => 99, 'width' => 160, 'height' => 50],
            ],
            'signature_request_id' => $this->prepared->id,
        ])->assertOk();

        expect($this->prepared->fresh()->x)->toBe(111.0)
            // The extra placement for a slot that isn't theirs is ignored.
            ->and($this->attested->fresh()->x)->toBe(300.0);
    });

    it('reports completion when the last required slot is signed', function () use ($url) {
        app(SigningSessionManager::class)->sign($this->prepared, 11);

        $mariaSig = Signature::where('user_id', 12)->first();

        $this->actingAs(TestUser::find(12))
            ->postJson($url($mariaSig->uuid), [
                'placements'           => [['slot' => 'attested_by', 'page' => 1, 'x' => 300, 'y' => 90, 'width' => 160, 'height' => 50]],
                'signature_request_id' => $this->attested->fresh()->id,
            ])
            ->assertOk()
            ->assertJsonPath('session.status', 'complete')
            ->assertJsonPath('session.outstanding', []);
    });
});
