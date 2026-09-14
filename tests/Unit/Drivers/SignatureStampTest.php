<?php

use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\DrawsSignatureStamp;

/**
 * The caption layout.
 *
 * Its whole job is to stay inside the rectangle a signatory placed. Everything
 * below is a way for that to go wrong: a box too short to share, lines too wide
 * to fit, a configuration that asks for more than there is room for.
 */
function stampSubject(): object
{
    return new class
    {
        use DrawsSignatureStamp {
            layoutCaption as public;
            qrSize as public;
        }
    };
}

function captionLayout(array $lines, float $width, float $height): array
{
    // A real TCPDF, because the layout asks it how wide the text will actually
    // be. Measuring against a stub would test the arithmetic and not the fit.
    $pdf = new TCPDF('P', 'pt');
    $pdf->AddPage();

    return stampSubject()->layoutCaption($pdf, $lines, $width, $height);
}

describe('signature caption layout', function () {

    it('reserves part of the box and leaves the rest to the signature', function () {
        $layout = captionLayout(['Juan Dela Cruz', 'Signed 14 Sep 2026 09:12'], 160, 60);

        expect($layout['lines'])->toHaveCount(2)
            ->and($layout['height'])->toBeGreaterThan(0)
            // The image gets whatever is left, so the caption must never take
            // the whole box or approach it.
            ->and($layout['height'])->toBeLessThan(60 * 0.4);
    });

    it('stands down entirely on a box too short to share', function () {
        // A signature squeezed into nothing is worse than one with no caption.
        $layout = captionLayout(['Juan Dela Cruz'], 160, 18);

        expect($layout['lines'])->toBe([])
            ->and($layout['height'])->toBe(0.0);
    });

    it('drops the lines that do not fit rather than overflowing', function () {
        $layout = captionLayout(
            ['Juan Dela Cruz', 'juan@example.test', 'Signed 14 Sep 2026 09:12', 'Ref a1b2c3d4'],
            160,
            30,
        );

        expect(count($layout['lines']))->toBeLessThan(4)
            ->and($layout['height'])->toBeLessThanOrEqual(30 * 0.38 + 0.01);
    });

    it('truncates a line too wide for the box instead of spilling out of it', function () {
        $layout = captionLayout(
            ['Bartholomew Fitzgerald-Montgomery of the Northern Districts Office'],
            60,
            60,
        );

        expect($layout['lines'])->toHaveCount(1)
            ->and($layout['lines'][0])->toEndWith('…')
            ->and(mb_strlen($layout['lines'][0]))
            ->toBeLessThan(mb_strlen('Bartholomew Fitzgerald-Montgomery of the Northern Districts Office'));
    });

    it('picks a bigger font when there is room for one', function () {
        $roomy  = captionLayout(['Juan'], 300, 120);
        $narrow = captionLayout(['Juan'], 300, 30);

        expect($roomy['size'])->toBeGreaterThanOrEqual($narrow['size']);
    });

    it('never exceeds the configured font range', function () {
        config()->set('signature.caption.max_font_pt', 6);
        config()->set('signature.caption.min_font_pt', 4);

        $layout = captionLayout(['Juan'], 400, 200);

        expect($layout['size'])->toBeLessThanOrEqual(6.0)
            ->and($layout['size'])->toBeGreaterThanOrEqual(4.0);
    });

    it('can be switched off entirely', function () {
        config()->set('signature.caption.enabled', false);

        expect(captionLayout(['Juan Dela Cruz'], 160, 60)['lines'])->toBe([]);
    });

    it('ignores blank lines rather than reserving space for them', function () {
        $layout = captionLayout(['Juan Dela Cruz', '', '   '], 160, 60);

        expect($layout['lines'])->toBe(['Juan Dela Cruz']);
    });
});

/**
 * The verification QR.
 *
 * It now sits inside the placement rather than beside it, which means it is
 * competing with the signature for the same width — so the rules about when it
 * stands down are the ones worth pinning.
 */
describe('verification QR sizing', function () {

    it('fits a square inside the placement without crowding out the signature', function () {
        $size = stampSubject()->qrSize(200, 60, true);

        expect($size)->toBeGreaterThan(0.0)
            // Never more than a third of the width, or the signature stops
            // being the main mark on the page.
            ->and($size)->toBeLessThanOrEqual(200 / 3)
            ->and($size)->toBeLessThanOrEqual(60.0);
    });

    it('stands down when the box cannot hold a scannable square', function () {
        // An unreadable barcode on a legal document is a promise the document
        // cannot keep.
        expect(stampSubject()->qrSize(60, 18, true))->toBe(0.0);
    });

    it('draws nothing when there is no payload to encode', function () {
        expect(stampSubject()->qrSize(200, 60, false))->toBe(0.0);
    });

    it('can be switched off entirely', function () {
        config()->set('signature.qr.enabled', false);

        expect(stampSubject()->qrSize(200, 60, true))->toBe(0.0);
    });

    it('honours a configured minimum', function () {
        config()->set('signature.qr.min_size', 100);

        expect(stampSubject()->qrSize(200, 60, true))->toBe(0.0);
    });
});
