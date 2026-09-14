<?php

use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignaturePosition;
use Illuminate\Support\Facades\Storage;

describe('PdfSignerService', function () {

    beforeEach(function () {
        Storage::fake('testing');

        Storage::disk('testing')->put('docs/test.pdf', minimalPdf());

        // An OPAQUE png — see stampablePng(). A transparent one sends TCPDF
        // down ImagePngAlpha(), which fatals and silently kills the runner.
        Storage::disk('testing')->put('signatures/test_sig.png', stampablePng());

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
