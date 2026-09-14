<?php

use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Services\AutoAffixService;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Support\FilamentVersion;

describe('package boot', function () {

    it('resolves every class name the docs tell host apps to import', function () {
        // Several of these exist only because the service provider aliased
        // them during register(). If that ordering ever breaks, a host app
        // gets "class not found" on a name the README told them to use.
        $names = [
            \Kukux\DigitalSignature\Filament\Resources\SignatureResource::class,
            \Kukux\DigitalSignature\Filament\Actions\SignDocumentAction::class,
            \Kukux\DigitalSignature\Filament\Actions\SignDocumentHeaderAction::class,
            \Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction::class,
            \Kukux\DigitalSignature\Filament\Actions\RequestSignaturesTableAction::class,
            \Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner::class,
            \Kukux\DigitalSignature\Filament\Pages\PdfTemplateSigner::class,
            \Kukux\DigitalSignature\Filament\Pages\SignatureInbox::class,
            \Kukux\DigitalSignature\Filament\Components\SignatoryPanel::class,
        ];

        foreach ($names as $name) {
            expect(class_exists($name))->toBeTrue("[{$name}] did not resolve");
        }
    });

    it('binds the routing and session services', function () {
        expect(app(SignatoryRouter::class))->toBeInstanceOf(SignatoryRouter::class)
            ->and(app(SigningSessionManager::class))->toBeInstanceOf(SigningSessionManager::class)
            ->and(app(AutoAffixService::class))->toBeInstanceOf(AutoAffixService::class)
            ->and(app(PdfSignerDriver::class))->toBeInstanceOf(PdfSignerDriver::class);
    });

    it('detects the Filament version without needing the config repository', function () {
        // FilamentVersion is called from register(), which can run before the
        // config repository is usable. It must degrade, not throw.
        FilamentVersion::flush();

        expect(FilamentVersion::major())->toBeIn([3, 4, 5]);
    });

    it('ships a default for every config key the new subsystems read', function () {
        foreach ([
            'signature.sessions.sequence_mode',
            'signature.multi_signature.mode',
            'signature.auto_affix.mode',
            'signature.auto_affix.allow_implicit',
            'signature.auto_affix.notify',
            'signature.auto_affix.default_grant_days',
            'signature.inbox.enabled',
        ] as $key) {
            expect(config()->has($key))->toBeTrue("[{$key}] has no default");
        }
    });

    it('defaults to the safe consent and signing modes', function () {
        // Guards against someone flipping a default that changes who can sign
        // on whose behalf.
        expect(config('signature.auto_affix.mode'))->toBe('approval')
            ->and(config('signature.auto_affix.allow_implicit'))->toBeFalse()
            ->and(config('signature.auto_affix.notify'))->toBeTrue()
            ->and(config('signature.multi_signature.mode'))->toBe('progressive');
    });
});
