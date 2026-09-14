<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Events\SignatureAutoAffixed;
use Kukux\DigitalSignature\Exceptions\DelegationNotPermittedException;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SignatureDelegation;
use Kukux\DigitalSignature\Notifications\SignatureAutoAffixedNotification;
use Kukux\DigitalSignature\Services\AutoAffixService;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;

describe('AutoAffixService', function () {

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

        $this->juanSig  = makePrimarySignature(11);
        $this->mariaSig = makePrimarySignature(12);

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
                Storage::disk('testing')->put($out, 'signed-'.$sig->id);
                $sig->update([
                    'signed_document_path' => $out,
                    'signed_document_hash' => hash('sha256', 'signed-'.$sig->id),
                    'status'               => 'signed',
                    'signed_at'            => now(),
                ]);
            });

        app()->instance(SignatureManager::class, $manager);
        app()->forgetInstance(SigningSessionManager::class);
        app()->forgetInstance(AutoAffixService::class);
    });

    afterEach(fn () => Mockery::close());

    it('never auto-signs in the default approval mode', function () {
        config()->set('signature.auto_affix.mode', 'approval');

        $session = app(SigningSessionManager::class)->open($this->report);
        $applied = app(AutoAffixService::class)->process($session);

        expect($applied)->toBeEmpty()
            ->and($session->requests->every(fn ($r) => $r->state !== RouteState::Signed))->toBeTrue();
    });

    it('does not auto-sign in delegated mode without a grant', function () {
        config()->set('signature.auto_affix.mode', 'delegated');

        $session = app(SigningSessionManager::class)->open($this->report);

        expect(app(AutoAffixService::class)->process($session))->toBeEmpty();
    });

    it('auto-signs only the slots a grant covers', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        // Only Juan has authorised auto-signing.
        auth()->loginUsingId(11);
        app(AutoAffixService::class)->grant(
            grantorId: 11,
            signature: $this->juanSig,
            templateKey: 'accomplishment-report',
            role: 'prepared_by',
        );
        auth()->logout();

        $session = app(SigningSessionManager::class)->open($this->report);
        $applied = app(AutoAffixService::class)->process($session);

        $session->refresh();

        expect($applied)->toHaveCount(1)
            ->and($session->requests->firstWhere('slot_key', 'prepared_by')->state)->toBe(RouteState::Signed)
            ->and($session->requests->firstWhere('slot_key', 'attested_by')->state)->not->toBe(RouteState::Signed);
    });

    it('marks an auto-affixed signature as source=auto', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        auth()->loginUsingId(11);
        app(AutoAffixService::class)->grant(11, $this->juanSig, 'accomplishment-report', 'prepared_by');
        auth()->logout();

        $session = app(SigningSessionManager::class)->open($this->report);
        [$signature] = app(AutoAffixService::class)->process($session);

        expect($signature->source)->toBe('auto')
            ->and($signature->wasAutoAffixed())->toBeTrue()
            // The originating fingerprint is carried forward rather than
            // fabricated — the owner was not at a keyboard.
            ->and($signature->machine_fingerprint)->toBe($this->juanSig->machine_fingerprint);
    });

    it('audits every auto-affix with the authorising grant', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        auth()->loginUsingId(11);
        $grant = app(AutoAffixService::class)->grant(11, $this->juanSig, 'accomplishment-report', 'prepared_by');
        auth()->logout();

        $session = app(SigningSessionManager::class)->open($this->report);
        app(AutoAffixService::class)->process($session);

        $audit = SignatureAudit::where('event', SignatureAudit::SIGNATURE_AUTO_AFFIXED)->first();

        expect($audit)->not->toBeNull()
            ->and($audit->subject_user_id)->toBe(11)
            ->and($audit->delegation_id)->toBe($grant->id);
    });

    it('notifies the signatory about every auto-affix', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        auth()->loginUsingId(11);
        app(AutoAffixService::class)->grant(11, $this->juanSig, 'accomplishment-report', 'prepared_by');
        auth()->logout();

        app(AutoAffixService::class)->process(app(SigningSessionManager::class)->open($this->report));

        Notification::assertSentTo(
            \Kukux\DigitalSignature\Tests\Support\TestUser::find(11),
            SignatureAutoAffixedNotification::class,
        );
    });

    it('fires SignatureAutoAffixed so hosts can react', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();
        Event::fake([SignatureAutoAffixed::class]);

        auth()->loginUsingId(11);
        app(AutoAffixService::class)->grant(11, $this->juanSig, 'accomplishment-report', 'prepared_by');
        auth()->logout();

        app(AutoAffixService::class)->process(app(SigningSessionManager::class)->open($this->report));

        Event::assertDispatched(SignatureAutoAffixed::class);
    });

    it('honours a revoked grant immediately', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        auth()->loginUsingId(11);
        $grant = app(AutoAffixService::class)->grant(11, $this->juanSig, 'accomplishment-report', 'prepared_by');
        app(AutoAffixService::class)->revokeGrant($grant);
        auth()->logout();

        expect(app(AutoAffixService::class)->process(
            app(SigningSessionManager::class)->open($this->report)
        ))->toBeEmpty();
    });

    it('ignores an expired grant', function () {
        config()->set('signature.auto_affix.mode', 'delegated');

        SignatureDelegation::create([
            'user_id'      => 11,
            'signature_id' => $this->juanSig->id,
            'template_key' => 'accomplishment-report',
            'role'         => 'prepared_by',
            'expires_at'   => now()->subDay(),
        ]);

        expect(app(AutoAffixService::class)->process(
            app(SigningSessionManager::class)->open($this->report)
        ))->toBeEmpty();
    });

    it('stops using a grant once max_uses is reached', function () {
        config()->set('signature.auto_affix.mode', 'delegated');
        Notification::fake();

        SignatureDelegation::create([
            'user_id'      => 11,
            'signature_id' => $this->juanSig->id,
            'template_key' => 'accomplishment-report',
            'role'         => 'prepared_by',
            'max_uses'     => 1,
            'uses'         => 1,
        ]);

        expect(app(AutoAffixService::class)->process(
            app(SigningSessionManager::class)->open($this->report)
        ))->toBeEmpty();
    });

    it('refuses a grant created by anyone but the grantor', function () {
        auth()->loginUsingId(12); // Maria, acting as an admin

        expect(fn () => app(AutoAffixService::class)->grant(
            grantorId: 11,             // ...trying to consent for Juan
            signature: $this->juanSig,
            templateKey: 'accomplishment-report',
        ))->toThrow(DelegationNotPermittedException::class);
    });

    it('refuses a grant over a signature the grantor does not own', function () {
        auth()->loginUsingId(11);

        expect(fn () => app(AutoAffixService::class)->grant(
            grantorId: 11,
            signature: $this->mariaSig,
            templateKey: 'accomplishment-report',
        ))->toThrow(DelegationNotPermittedException::class);
    });

    it('refuses implicit mode unless it is explicitly acknowledged', function () {
        config()->set('signature.auto_affix.mode', 'implicit');
        config()->set('signature.auto_affix.allow_implicit', false);

        expect(fn () => app(AutoAffixService::class)->process(
            app(SigningSessionManager::class)->open($this->report)
        ))->toThrow(DelegationNotPermittedException::class);
    });

    it('signs without a grant once implicit mode is acknowledged', function () {
        config()->set('signature.auto_affix.mode', 'implicit');
        config()->set('signature.auto_affix.allow_implicit', true);
        Notification::fake();

        $applied = app(AutoAffixService::class)->process(
            app(SigningSessionManager::class)->open($this->report)
        );

        expect($applied)->toHaveCount(2);
    });
});
