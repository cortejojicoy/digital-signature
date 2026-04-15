<?php

use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignaturePosition;
use Illuminate\Support\Facades\Storage;

describe('PdfSignerService', function () {

    beforeEach(function () {
        Storage::fake('testing');

        // Put a minimal real PDF on the fake disk for FPDI to read
        $minimalPdf = base64_decode(
            'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlIC9DYXRhbG9nIC9QYWdlcyAyIDAgUj4+CmVuZG9iagoy'
            .'IDAgb2JqCjw8L1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDE+PgplbmRvYmoKMyAw'
            .'IG9iago8PC9UeXBlIC9QYWdlIC9QYXJlbnQgMiAwIFIgL01lZGlhQm94IFswIDAgNjEyIDc5Ml0+'
            .'PgplbmRvYmoKeHJlZgowIDQKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4g'
            .'CjAwMDAwMDAwNTYgMDAwMDAgbiAKMDAwMDAwMDExMSAwMDAwMCBuIAp0cmFpbGVyCjw8L1NpemUg'
            .'NCAvUm9vdCAxIDAgUj4+CnN0YXJ0eHJlZgoxODAKJSVFT0YK'
        );

        Storage::disk('testing')->put('docs/test.pdf', $minimalPdf);

        // Put a 1×1 PNG signature image
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
        );
        Storage::disk('testing')->put('signatures/test_sig.png', $pngBytes);

        $this->service = new PdfSignerService(new FpdiDriver());
    });

    it('produces a signed PDF file on disk', function () {
        $user = makeFakeUser();

        $sig = Signature::create([
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('a', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);

        // Mock the signable
        $signableMock = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signableMock->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signableMock);

        SignaturePosition::create([
            'signature_id' => $sig->id,
            'page' => 1, 'x' => 40, 'y' => 700, 'width' => 160, 'height' => 60,
        ]);
        $sig->load('position');

        $outPath = $this->service->sign($sig, []);

        expect($outPath)->toBeString()->toContain('signed-docs/');
        Storage::disk('testing')->assertExists($outPath);
    });
});
