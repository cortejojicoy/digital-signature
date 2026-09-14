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

    it('hands the driver every place the signature is stamped', function () {
        $user = makeFakeUser();

        $sig = Signature::create([
            'uuid'       => 'aaaabbbb-0000-0000-0000-000000000000',
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('d', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);
        $sig->setRelation('user', $user);

        $signable = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signable->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signable);

        // One signature, three appearances — the signature block, the
        // certificate, the acceptance clause.
        foreach ([[1, 90.0], [1, 300.0], [2, 500.0]] as [$page, $y]) {
            SignaturePosition::create([
                'signature_id' => $sig->id,
                'page' => $page, 'x' => 100, 'y' => $y, 'width' => 160, 'height' => 50,
            ]);
        }

        $driver = Mockery::mock(\Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver::class);
        $driver->shouldReceive('sign')
            ->once()
            ->andReturnUsing(function (...$args) {
                $this->args = $args;

                return 'signed-docs/out.pdf';
            });

        (new PdfSignerService($driver))->sign($sig, []);

        $primary = $this->args[2];
        $extra   = $this->args[7];

        expect($primary['y'])->toBe(90.0)
            ->and($extra)->toHaveCount(2)
            ->and($extra[0]['y'])->toBe(300.0)
            // A stamp on another page is still the same signature.
            ->and($extra[1]['page'])->toBe(2);
    });

    it('sends no extra positions for an ordinary single stamp', function () {
        $user = makeFakeUser();

        $sig = Signature::create([
            'uuid'       => 'ccccdddd-0000-0000-0000-000000000000',
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('e', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);
        $sig->setRelation('user', $user);

        $signable = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signable->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signable);

        SignaturePosition::create([
            'signature_id' => $sig->id,
            'page' => 1, 'x' => 40, 'y' => 700, 'width' => 160, 'height' => 60,
        ]);

        $driver = Mockery::mock(\Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver::class);
        $driver->shouldReceive('sign')
            ->once()
            ->andReturnUsing(function (...$args) {
                $this->args = $args;

                return 'signed-docs/out.pdf';
            });

        (new PdfSignerService($driver))->sign($sig, []);

        expect($this->args[7])->toBe([]);
    });

    it('stamps a real PDF in several places without falling over', function () {
        // The driver loop runs per page and per stamp; a smoke test catches an
        // off-by-one there that a mocked driver never would.
        $user = makeFakeUser();

        $sig = Signature::create([
            'uuid'       => 'eeeeffff-0000-0000-0000-000000000000',
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('f', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);
        $sig->setRelation('user', $user);

        $signable = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signable->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signable);

        foreach ([120.0, 340.0, 560.0] as $y) {
            SignaturePosition::create([
                'signature_id' => $sig->id,
                'page' => 1, 'x' => 40, 'y' => $y, 'width' => 160, 'height' => 60,
            ]);
        }

        $outPath = $this->service->sign($sig, []);

        Storage::disk('testing')->assertExists($outPath);
    });

    it('hands the driver readable provenance to draw under the signature', function () {
        $user = makeFakeUser();

        $sig = Signature::create([
            'uuid'       => 'a1b2c3d4-0000-0000-0000-000000000000',
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('b', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);
        $sig->setRelation('user', $user);

        $signable = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signable->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signable);

        $driver = Mockery::mock(\Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver::class);
        $driver->shouldReceive('sign')
            ->once()
            ->andReturnUsing(function (...$args) {
                // Named arguments arrive keyed, so read the one we care about
                // rather than relying on positional order.
                $this->caption = $args[6] ?? [];

                return 'signed-docs/out.pdf';
            });

        (new PdfSignerService($driver))->sign($sig, []);

        expect($this->caption)->toHaveCount(3)
            ->and($this->caption[0])->toBe('Test User')
            ->and($this->caption[1])->toStartWith('Signed ')
            // The reference is what somebody reading the printout quotes back.
            ->and($this->caption[2])->toBe('Ref a1b2c3d4');
    });

    it('sends no caption when captions are switched off', function () {
        config()->set('signature.caption.enabled', false);

        $user = makeFakeUser();

        $sig = Signature::create([
            'uuid'       => 'a1b2c3d4-0000-0000-0000-000000000000',
            'user_id'    => $user->id,
            'image_path' => 'signatures/test_sig.png',
            'image_hash' => str_repeat('c', 64),
            'source'     => 'draw',
            'status'     => 'pending',
        ]);
        $sig->setRelation('user', $user);

        $signable = Mockery::mock(\Kukux\DigitalSignature\Contracts\Signable::class);
        $signable->shouldReceive('getSignablePdfPath')->andReturn('docs/test.pdf');
        $sig->setRelation('signable', $signable);

        $driver = Mockery::mock(\Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver::class);
        $driver->shouldReceive('sign')
            ->once()
            ->andReturnUsing(function (...$args) {
                $this->args = $args;

                return 'signed-docs/out.pdf';
            });

        (new PdfSignerService($driver))->sign($sig, []);

        // Assert the argument arrived and was empty, not merely that reading
        // position 6 produced nothing — those are different failures. Checked
        // by key rather than by argument count, so adding a later parameter to
        // the driver contract does not fail this for an unrelated reason.
        expect($this->args)->toHaveKey(6)
            ->and($this->args[6])->toBe([]);
    });
});
