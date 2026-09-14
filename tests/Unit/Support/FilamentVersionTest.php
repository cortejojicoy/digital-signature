<?php

use Kukux\DigitalSignature\Support\FilamentVersion;

describe('FilamentVersion', function () {

    afterEach(function () {
        FilamentVersion::fake(null);
        config()->set('signature.filament_version', null);
        FilamentVersion::flush();
    });

    it('reads the real installed major from composer, not a class probe', function () {
        FilamentVersion::flush();

        // Pretty versions carry a leading "v" (e.g. "v5.5.0"), so strip it
        // before comparing — the same thing FilamentVersion does internally.
        $installed = (int) ltrim(
            explode('.', \Composer\InstalledVersions::getPrettyVersion('filament/filament'))[0],
            'v',
        );

        // The point of this class: a class probe reports "4" for anything that
        // has Filament\Schemas\Schema — which is v4 AND v5. Composer can tell
        // them apart, and "supports v5" is unverifiable without that.
        expect(FilamentVersion::major())->toBe($installed);
    });

    it('treats 4 and 5 as the schemas branch and 3 as legacy', function () {
        FilamentVersion::fake(3);
        expect(FilamentVersion::usesSchemas())->toBeFalse()
            ->and(FilamentVersion::usesLegacyTableActions())->toBeTrue()
            ->and(FilamentVersion::implementationNamespace())->toBe('V3');

        FilamentVersion::fake(4);
        expect(FilamentVersion::usesSchemas())->toBeTrue()
            ->and(FilamentVersion::implementationNamespace())->toBe('V4');

        FilamentVersion::fake(5);
        expect(FilamentVersion::usesSchemas())->toBeTrue()
            ->and(FilamentVersion::usesLegacyTableActions())->toBeFalse()
            ->and(FilamentVersion::implementationNamespace())->toBe('V4');
    });

    it('honours an explicit config override', function () {
        config()->set('signature.filament_version', 3);
        FilamentVersion::flush();

        expect(FilamentVersion::major())->toBe(3);
    });
});
