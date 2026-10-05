<?php

use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\DrawsSignatureStamp;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureCaption;

/**
 * The stamp layout: COA Circular No. 2021-006, IV.C.13 — the handwritten
 * signature with the signatory's full name and the date beside it.
 *
 * Its whole job is to stay inside the rectangle a signatory placed, keep the
 * text at one size whatever that rectangle is, and give the rest to the ink.
 */
function stampSubject(): object
{
    return new class
    {
        use DrawsSignatureStamp {
            layoutCaption as public;
            stampFrame as public;
        }
    };
}

function stampPdf(): TCPDF
{
    // A real TCPDF, because the layout asks it how wide the text will actually
    // be. Measuring against a stub would test the arithmetic and not the fit.
    $pdf = new TCPDF('P', 'pt');
    $pdf->AddPage();

    return $pdf;
}

function captionLayout(array $lines, float $width, float $height): array
{
    return stampSubject()->layoutCaption(stampPdf(), $lines, $width, $height);
}

function stampFrame(array $lines, float $w, float $h, ?float $aspect = null): array
{
    return stampSubject()->stampFrame(stampPdf(), $w, $h, $lines, $aspect);
}

const COA_LINES = ['Digitally signed', 'by Juan DelaCruz', 'Date: 2020.05.21', "19:37:33 +08'00'"];

describe('COA caption text', function () {

    it('formats the lines as the circular shows them', function () {
        config()->set('signature.caption.timezone', 'Asia/Manila');

        $user = new class extends Illuminate\Foundation\Auth\User
        {
            protected $attributes = ['name' => 'Juan DelaCruz'];
        };

        $sig = new Signature;
        $sig->setRelation('user', $user);

        $lines = (new SignatureCaption)->linesFor($sig, Carbon::parse('2020-05-21 11:37:33', 'UTC'));

        expect($lines)->toBe(COA_LINES);
    });

    it('carries no reference, email or QR rules', function () {
        $rules = (new SignatureCaption)->rules();

        expect($rules)->toHaveKey('caption')
            ->and($rules)->not->toHaveKey('qr');
    });
});

describe('caption sizing', function () {

    it('draws at the configured size regardless of how big the box is', function () {
        config()->set('signature.caption.font_pt', 7);

        $small = captionLayout(COA_LINES, 220, 60);
        $large = captionLayout(COA_LINES, 600, 300);

        expect($small['size'])->toBe(7.0)
            ->and($large['size'])->toBe(7.0)
            ->and($large['width'])->toBe($small['width'])
            ->and($large['height'])->toBe($small['height']);
    });

    it('steps down only when the box cannot hold it, never below the minimum', function () {
        config()->set('signature.caption.font_pt', 7);
        config()->set('signature.caption.min_font_pt', 4);

        $cramped = captionLayout(COA_LINES, 90, 22);

        expect($cramped['size'])->toBeLessThan(7.0)
            ->and($cramped['size'])->toBeGreaterThanOrEqual(4.0);
    });

    it('never truncates the name', function () {
        $long  = 'by Bartholomew Fitzgerald-Montgomery y Dela Cruz';
        $layout = captionLayout(['Digitally signed', $long], 60, 20);

        expect($layout['lines'][1])->toBe($long);
    });

    it('can be switched off entirely', function () {
        config()->set('signature.caption.enabled', false);

        expect(captionLayout(COA_LINES, 220, 60)['lines'])->toBe([]);
    });

    it('ignores blank lines rather than reserving space for them', function () {
        $layout = captionLayout(['by Juan DelaCruz', '', '   '], 160, 60);

        expect($layout['lines'])->toBe(['by Juan DelaCruz']);
    });
});

describe('ink and caption layout', function () {

    it('puts the ink on the left and the text beside it, inside the box', function () {
        $frame = stampFrame(COA_LINES, 220, 60, 3.0);

        expect($frame['caption']['x'])->toBeGreaterThanOrEqual($frame['image']['x'] + $frame['image']['w'])
            ->and($frame['image']['x'])->toBeGreaterThanOrEqual(0.0)
            ->and($frame['image']['y'])->toBeGreaterThanOrEqual(0.0)
            ->and($frame['image']['y'] + $frame['image']['h'])->toBeLessThanOrEqual(60.01)
            ->and($frame['caption']['x'] + $frame['caption']['w'])->toBeLessThanOrEqual(220.01)
            ->and($frame['caption']['y'] + $frame['caption']['height'])->toBeLessThanOrEqual(60.01);
    });

    it('scales only the signature when the box grows', function () {
        $small = stampFrame(COA_LINES, 200, 50, 3.0);
        $large = stampFrame(COA_LINES, 400, 120, 3.0);

        expect($large['image']['w'])->toBeGreaterThan($small['image']['w'])
            ->and($large['caption']['size'])->toBe($small['caption']['size'])
            ->and($large['caption']['w'])->toBe($small['caption']['w']);
    });

    it('keeps the text tucked beside the ink at every box size', function () {
        $gap = (float) config('signature.caption.gap', 3);

        foreach ([200, 300, 500] as $width) {
            $frame = stampFrame(COA_LINES, (float) $width, 80, 3.0);
            $inkRight = $frame['image']['x'] + $frame['image']['w'];

            expect(abs($frame['caption']['x'] - $inkRight - $gap))
                ->toBeLessThan(0.01, "caption drifted at width {$width}");
        }
    });

    it('keeps the signature at its own proportions', function () {
        foreach ([1.0, 2.5, 6.0] as $aspect) {
            $frame = stampFrame(COA_LINES, 300, 160, $aspect);

            expect(abs($frame['image']['w'] / $frame['image']['h'] - $aspect))
                ->toBeLessThan(0.01, "aspect {$aspect} not preserved");
        }
    });

    it('centres the text against the box vertically', function () {
        $frame = stampFrame(COA_LINES, 300, 120, 3.0);

        $above = $frame['caption']['y'];
        $below = 120 - ($frame['caption']['y'] + $frame['caption']['height']);

        expect(abs($above - $below))->toBeLessThan(0.5);
    });

    it('fills the box when there is no text and no aspect to honour', function () {
        $frame = stampFrame([], 300, 100, null);

        expect($frame['image']['w'])->toBe(300.0)
            ->and($frame['image']['h'])->toBe(100.0)
            ->and($frame['image']['x'])->toBe(0.0);
    });
});
