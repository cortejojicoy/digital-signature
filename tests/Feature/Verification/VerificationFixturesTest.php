<?php

use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Fixtures/verification/generate.php';

/**
 * Writes tests/Fixtures/verification/*. Skipped unless asked for; see
 * generate.php for what it makes and why the output is committed.
 */
it('regenerates the signed-PDF fixtures for the JS verifier', function () {
    Storage::fake('testing');

    generateVerificationFixtures(realpath(__DIR__.'/../../Fixtures/verification'));

    expect(file_exists(__DIR__.'/../../Fixtures/verification/v2.pdf'))->toBeTrue();
})->skip(fn () => getenv('GENERATE_VERIFICATION_FIXTURES') !== '1', 'set GENERATE_VERIFICATION_FIXTURES=1 to regenerate');
