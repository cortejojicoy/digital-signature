<?php

use Kukux\DigitalSignature\Filament\Actions\ActionResolver;
use Kukux\DigitalSignature\Filament\Actions\HeaderActionResolver;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesignerResolver;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateSignerResolver;
use Kukux\DigitalSignature\Filament\Pages\SignatureInboxResolver;
use Kukux\DigitalSignature\Filament\Resources\ResourceResolver;
use Kukux\DigitalSignature\Support\FilamentVersion;

describe('component resolution across Filament majors', function () {

    afterEach(function () {
        FilamentVersion::fake(null);
        FilamentVersion::flush();
    });

    $resolvers = [
        ResourceResolver::class,
        ActionResolver::class,
        HeaderActionResolver::class,
        PdfTemplateDesignerResolver::class,
        PdfTemplateSignerResolver::class,
        SignatureInboxResolver::class,
    ];

    it('picks the V3 implementation on Filament 3', function () use ($resolvers) {
        FilamentVersion::fake(3);

        foreach ($resolvers as $resolver) {
            expect($resolver::resolveImplementationClass())->toContain('\\V3\\');
        }
    });

    it('picks the V4 implementation on Filament 4 and 5', function () use ($resolvers) {
        foreach ([4, 5] as $major) {
            FilamentVersion::fake($major);

            foreach ($resolvers as $resolver) {
                expect($resolver::resolveImplementationClass())->toContain('\\V4\\');
            }
        }
    });

    it('aliases every canonical name the provider registers', function () use ($resolvers) {
        // The service provider ran during app boot; these names only exist
        // because of class_alias, which is what host apps import.
        foreach ($resolvers as $resolver) {
            expect(class_exists($resolver::CANONICAL))->toBeTrue();
        }
    });

    it('only ever loads the implementation matching the installed version', function () {
        // Loading the WRONG version's page class is a hard PHP fatal, in both
        // directions: Page::$view is static on v3 and an instance property on
        // v4+, so each class is unloadable on the other major. The resolver
        // must therefore never touch the one it didn't pick — asserting that
        // "both classes load" would itself crash the suite.
        $wrongNamespace = FilamentVersion::usesSchemas() ? '\\V3\\' : '\\V4\\';

        foreach ([
            PdfTemplateDesignerResolver::class,
            PdfTemplateSignerResolver::class,
            SignatureInboxResolver::class,
        ] as $resolver) {
            $chosen = $resolver::resolveImplementationClass();

            expect($chosen)->not->toContain($wrongNamespace)
                ->and(class_exists($chosen))->toBeTrue();

            // The unpicked class must still exist on disk — it just must not
            // be autoloaded. class_exists(autoload: false) checks without
            // triggering the fatal.
            $other = str_replace(
                FilamentVersion::usesSchemas() ? '\\V4\\' : '\\V3\\',
                $wrongNamespace,
                $chosen,
            );

            expect(class_exists($other, autoload: false))->toBeFalse();
        }
    });

    it('ships a file for both version implementations of every split component', function () {
        // Checked on disk rather than by loading, for the reason above.
        $root = __DIR__.'/../../../src/Filament';

        foreach ([
            'Pages/%s/PdfTemplateDesigner.php',
            'Pages/%s/PdfTemplateSigner.php',
            'Pages/%s/SignatureInbox.php',
            'Actions/%s/SignDocumentAction.php',
            'Actions/%s/SignDocumentHeaderAction.php',
            'Resources/%s/SignatureResource.php',
        ] as $pattern) {
            foreach (['V3', 'V4'] as $version) {
                expect(file_exists($root.'/'.sprintf($pattern, $version)))
                    ->toBeTrue(sprintf($pattern, $version).' is missing');
            }
        }
    });
});
