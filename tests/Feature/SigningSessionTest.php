<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Events\SigningSessionCompleted;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\IncrementalSigningUnsupportedException;
use Kukux\DigitalSignature\Exceptions\OutOfSequenceException;
use Kukux\DigitalSignature\Exceptions\SigningSessionClosedException;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;

describe('SigningSessionManager', function () {

    beforeEach(function () {
        Storage::fake('testing');
        arSessionTemplate();

        makeUser(11, 'Juan Dela Cruz');
        makeUser(12, 'Maria Santos');
        makeUser(13, 'Dr Reyes');

        makePrimarySignature(11);
        makePrimarySignature(12);
        makePrimarySignature(13);

        $this->report = AccomplishmentReport::create([
            'prepared_by_id' => 11,
            'attested_by_id' => 12,
            'noted_by_id'    => 13,
        ]);

        // The cryptographic pipeline has its own tests; here we care about the
        // session state machine, so stub the embedding step and record what
        // document each signature was asked to sign.
        $this->signed = [];
        // A partial mock built with the real constructor args, so
        // storeForDocument() still runs for real and only the crypto step
        // is stubbed.
        $this->manager = Mockery::mock(
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
        $this->manager->shouldAllowMockingProtectedMethods();
        $this->manager->shouldReceive('embedAndFinalize')
            ->andReturnUsing(function (Signature $sig, string $pw, ?string $source = null) {
                $this->signed[] = $source;
                $out = 'signed-docs/sig-'.$sig->id.'.pdf';
                Storage::disk('testing')->put($out, 'signed-pdf-'.$sig->id);
                $sig->update([
                    'signed_document_path' => $out,
                    'signed_document_hash' => hash('sha256', 'signed-pdf-'.$sig->id),
                    'status'               => 'signed',
                    'signed_at'            => now(),
                ]);
            });

        app()->instance(SignatureManager::class, $this->manager);
        app()->forgetInstance(SigningSessionManager::class);
    });

    afterEach(fn () => Mockery::close());

    it('freezes the document once and creates a request per slot', function () {
        $session = app(SigningSessionManager::class)->open($this->report);

        expect($session->status)->toBe(SigningSession::STATUS_OPEN)
            ->and($session->requests)->toHaveCount(3)
            ->and(Storage::disk('testing')->exists($session->base_document_path))->toBeTrue()
            ->and($session->base_document_hash)->not->toBeNull();
    });

    it('copies the placement onto each request so a later designer edit cannot move it', function () {
        $session = app(SigningSessionManager::class)->open($this->report);

        $request = $session->requests->firstWhere('slot_key', 'attested_by');
        expect($request->position())->toBe([
            'page' => 1, 'x' => 300.0, 'y' => 90.0, 'width' => 160.0, 'height' => 50.0,
        ]);

        PdfTemplateSlot::where('slot_key', 'attested_by')->update(['x' => 999.0]);

        expect($request->fresh()->position()['x'])->toBe(300.0);
    });

    it('is idempotent — reopening returns the same session', function () {
        $first  = app(SigningSessionManager::class)->open($this->report);
        $second = app(SigningSessionManager::class)->open($this->report);

        expect($second->id)->toBe($first->id)
            ->and(SigningSession::count())->toBe(1);
    });

    it('chains each signature onto the previous one\'s output', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);

        $prepared = $session->requests->firstWhere('slot_key', 'prepared_by');
        $attested = $session->requests->firstWhere('slot_key', 'attested_by');

        $first  = $manager->sign($prepared, 11);
        $second = $manager->sign($attested->fresh(), 12);

        // Signature 2 signed signature 1's OUTPUT, not a fresh render — this
        // is what keeps the first stamp in the finished document.
        expect($this->signed[0])->toBe($session->base_document_path)
            ->and($this->signed[1])->toBe($first->signed_document_path)
            ->and($second->parent_signature_id)->toBe($first->id)
            ->and($second->document_hash)->toBe($first->signed_document_hash);
    });

    it('refuses an out-of-order signature in a sequential session', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);

        $notedBy = $session->requests->firstWhere('slot_key', 'noted_by');

        expect(fn () => $manager->sign($notedBy, 13))
            ->toThrow(OutOfSequenceException::class);
    });

    it('allows any order in a parallel session', function () {
        config()->set('signature.sessions.sequence_mode', 'parallel');
        arSessionTemplate();

        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $notedBy = $session->requests->firstWhere('slot_key', 'noted_by');

        expect($manager->sign($notedBy, 13))->toBeInstanceOf(Signature::class);
    });

    it('refuses a signature from anyone but the assigned signatory', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $prepared = $session->requests->firstWhere('slot_key', 'prepared_by');

        // Maria trying to sign Juan's slot — the core anti-forgery rule.
        expect(fn () => $manager->sign($prepared, 12))
            ->toThrow(ForgedSignatureException::class);
    });

    it('completes the session and fires the event once every required slot is signed', function () {
        Event::fake([SigningSessionCompleted::class]);

        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);

        foreach ([['prepared_by', 11], ['attested_by', 12], ['noted_by', 13]] as [$slot, $userId]) {
            $manager->sign($session->requests()->where('slot_key', $slot)->first(), $userId);
        }

        $session->refresh();

        expect($session->status)->toBe(SigningSession::STATUS_COMPLETE)
            ->and($session->completed_at)->not->toBeNull()
            ->and($session->current_document_path)->not->toBeNull();

        Event::assertDispatched(SigningSessionCompleted::class);
    });

    it('exposes the finished document through the host model', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);

        foreach ([['prepared_by', 11], ['attested_by', 12], ['noted_by', 13]] as [$slot, $userId]) {
            $manager->sign($session->requests()->where('slot_key', $slot)->first(), $userId);
        }

        expect($this->report->isFullySigned())->toBeTrue()
            ->and($this->report->signedDocumentPath())->not->toBeNull();
    });

    it('records a decline without closing the session', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $prepared = $session->requests->firstWhere('slot_key', 'prepared_by');

        $manager->decline($prepared, 11, 'Figures need revising');

        expect($prepared->fresh()->state)->toBe(RouteState::Declined)
            ->and($prepared->fresh()->declined_reason)->toBe('Figures need revising')
            ->and($session->fresh()->status)->toBe(SigningSession::STATUS_OPEN);
    });

    it('refuses signatures once the session is cancelled', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $prepared = $session->requests->firstWhere('slot_key', 'prepared_by');

        $manager->cancel($session);

        expect(fn () => $manager->sign($prepared->fresh(), 11))
            ->toThrow(SigningSessionClosedException::class);
    });

    it('fills in a signatory tagged after the session opened', function () {
        $this->report->update(['noted_by_id' => null]);

        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report->fresh());

        expect($session->requests->firstWhere('slot_key', 'noted_by')->user_id)->toBeNull();

        $this->report->update(['noted_by_id' => 13]);
        $manager->refreshAssignments($session->fresh(['requests']));

        expect(SignatureRequest::where('slot_key', 'noted_by')->first()->user_id)->toBe(13);
    });

    it('refuses incremental mode rather than silently invalidating earlier signatures', function () {
        // FPDI rebuilds the whole document on every pass, so it cannot append
        // a signature without destroying the ones before it. Asking for true
        // PAdES must fail loudly, not degrade into a lie.
        config()->set('signature.multi_signature.mode', 'incremental');

        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $prepared = $session->requests->firstWhere('slot_key', 'prepared_by');

        expect(fn () => $manager->sign($prepared, 11))
            ->toThrow(IncrementalSigningUnsupportedException::class);
    });

    it('accepts incremental mode when the driver supports it', function () {
        config()->set('signature.multi_signature.mode', 'incremental');

        app()->instance(
            \Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver::class,
            new \Kukux\DigitalSignature\Tests\Support\IncrementalCapableDriver(),
        );

        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);

        expect($manager->sign($session->requests->firstWhere('slot_key', 'prepared_by'), 11))
            ->toBeInstanceOf(Signature::class);
    });

    it('writes an audit row for every consequential act', function () {
        $manager = app(SigningSessionManager::class);
        $session = $manager->open($this->report);
        $manager->sign($session->requests->firstWhere('slot_key', 'prepared_by'), 11);

        expect(SignatureAudit::where('event', SignatureAudit::SESSION_OPENED)->exists())->toBeTrue()
            ->and(SignatureAudit::where('event', SignatureAudit::REQUEST_CREATED)->count())->toBe(3)
            ->and(SignatureAudit::where('event', SignatureAudit::REQUEST_SIGNED)->exists())->toBeTrue();
    });
});
