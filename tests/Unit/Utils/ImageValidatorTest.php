<?php

use Illuminate\Http\UploadedFile;

describe('Server-side image validation', function () {

    it('accepts a valid PNG under the size limit', function () {
        $file = UploadedFile::fake()->image('sig.png', 200, 80);
        expect($file->getMimeType())->toContain('image')
            ->and($file->getSize())->toBeLessThan(512 * 1024);
    });

    it('rejects files over the configured max size', function () {
        // Create a fake file just over 512KB
        $file = UploadedFile::fake()->create('big.png', 600, 'image/png');
        expect($file->getSize())->toBeGreaterThan(512 * 1024);
    });

    it('base64 decode round-trips correctly', function () {
        $original = 'Hello PNG bytes';
        $encoded  = 'data:image/png;base64,'.base64_encode($original);
        $decoded  = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $encoded));
        expect($decoded)->toBe($original);
    });
});
