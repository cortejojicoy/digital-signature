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
            stampFrame as public;
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

function stampFrame(array $lines, float $w, float $h, string $position, ?float $aspect = null): array
{
    $pdf = new TCPDF('P', 'pt');
    $pdf->AddPage();

    return stampSubject()->stampFrame($pdf, $w, $h, $lines, true, $position, $aspect);
}

/**
 * Which side of the stamp the caption takes.
 *
 * A form dictates this — a signature line with the printed name already
 * underneath has no room below and plenty beside it — so all four have to
 * produce a layout that stays inside the box the signatory placed.
 */
describe('caption position', function () {

    $lines = ['Paolo Rommel P. Sanchez', 'Signed 14 Sep 2026 03:49', 'Ref aef7f9a1'];

    it('keeps every element inside the placement on all four sides', function () use ($lines) {
        foreach (['bottom', 'top', 'left', 'right'] as $side) {
            $frame = stampFrame($lines, 220, 70, $side);

            expect($frame['image']['x'])->toBeGreaterThanOrEqual(0.0)
                ->and($frame['image']['y'])->toBeGreaterThanOrEqual(0.0)
                ->and($frame['image']['x'] + $frame['image']['w'])->toBeLessThanOrEqual(220.01)
                ->and($frame['image']['y'] + $frame['image']['h'])->toBeLessThanOrEqual(70.01)
                ->and($frame['caption']['x'] + $frame['caption']['w'])->toBeLessThanOrEqual(220.01);

            if ($frame['qr'] !== null) {
                expect($frame['qr']['x'] + $frame['qr']['size'])->toBeLessThanOrEqual(220.01)
                    ->and($frame['qr']['y'] + $frame['qr']['size'])->toBeLessThanOrEqual(70.01);
            }
        }
    });

    it('takes height below and beside gives the ink its height back', function () use ($lines) {
        $below  = stampFrame($lines, 220, 70, 'bottom');
        $beside = stampFrame($lines, 220, 70, 'left');

        expect($below['image']['h'])->toBeLessThan(70.0)
            ->and($beside['image']['h'])->toBe(70.0)
            ->and($beside['image']['x'])->toBeGreaterThan(0.0);
    });

    it('pushes the ink down for a caption on top', function () use ($lines) {
        $frame = stampFrame($lines, 220, 70, 'top');

        expect($frame['caption']['y'])->toBe(0.0)
            ->and($frame['image']['y'])->toBeGreaterThan(0.0);
    });

    it('falls back to the bottom for a side it does not know', function () use ($lines) {
        $odd    = stampFrame($lines, 220, 70, 'sideways');
        $bottom = stampFrame($lines, 220, 70, 'bottom');

        expect($odd['caption']['y'])->toBe($bottom['caption']['y']);
    });

    it('stands a side caption down on a box too narrow for a column', function () use ($lines) {
        // Beside the ink the limit is horizontal; a sliver of signature is
        // worse than an uncaptioned one.
        $frame = stampFrame($lines, 80, 70, 'left');

        expect($frame['caption']['lines'])->toBe([])
            ->and($frame['image']['x'])->toBe(0.0);
    });

    it('measures the QR against the content, not the whole box', function () use ($lines) {
        // With a caption down one side, sizing the QR against the original
        // width would push it over the text.
        $frame = stampFrame($lines, 220, 70, 'left');

        if ($frame['qr'] !== null) {
            expect($frame['qr']['x'] + $frame['qr']['size'])
                ->toBeLessThanOrEqual($frame['caption']['x'] + 220.01);
        }

        expect($frame['image']['w'])->toBeGreaterThan(0.0);
    });
});

/**
 * Where the ink sits, and where the caption sits relative to it.
 *
 * The caption used to be pinned to the box's bottom edge, so enlarging a
 * placement pushed the provenance further from the signature it describes
 * until it read as a note about whatever the form had underneath.
 */
describe('ink and caption grouping', function () {

    $lines = ['Moldez, Abril Aguinaldo', 'Signed 14 Sep 2026 03:42', 'Ref a7312061'];

    it('keeps the caption tucked under the ink at every box size', function () use ($lines) {
        foreach ([40, 70, 120, 240] as $height) {
            $frame = stampFrame($lines, 300, (float) $height, 'bottom', 4.0);
            $inkBottom = $frame['image']['y'] + $frame['image']['h'];

            expect(abs($frame['caption']['y'] - $inkBottom))
                ->toBeLessThan(0.01, "caption drifted at height {$height}");
        }
    });

    it('keeps the signature at its own proportions', function () use ($lines) {
        // A stretched signature is a stamp that no longer matches the
        // specimen on file.
        foreach ([1.0, 2.5, 6.0] as $aspect) {
            $frame = stampFrame($lines, 300, 160, 'bottom', $aspect);

            expect(abs($frame['image']['w'] / $frame['image']['h'] - $aspect))
                ->toBeLessThan(0.01, "aspect {$aspect} not preserved");
        }
    });

    it('fills the space when the image proportions cannot be read', function () {
        // With no caption and no QR, "the space" is the whole box — the
        // fallback for an unreadable file is the behaviour this had before
        // proportions were respected at all.
        config()->set('signature.qr.enabled', false);

        $frame = stampFrame([], 300, 100, 'bottom', null);

        expect($frame['image']['w'])->toBe(300.0)
            ->and($frame['image']['h'])->toBe(100.0);
    });

    it('still leaves the QR its square when proportions are unknown', function () {
        $frame = stampFrame([], 300, 100, 'bottom', null);

        expect($frame['qr'])->not->toBeNull()
            ->and($frame['image']['w'])->toBe(300.0 - $frame['qr']['size'] - 2.0);
    });

    it('centres the pair rather than the ink alone', function () use ($lines) {
        $frame = stampFrame($lines, 300, 200, 'bottom', 4.0);

        $top    = $frame['image']['y'];
        $bottom = 200 - ($frame['caption']['y'] + $frame['caption']['height']);

        expect(abs($top - $bottom))->toBeLessThan(0.5);
    });

    it('levels the QR with the ink', function () use ($lines) {
        $frame = stampFrame($lines, 300, 200, 'bottom', 4.0);

        expect($frame['qr'])->not->toBeNull();

        $inkMid = $frame['image']['y'] + $frame['image']['h'] / 2;
        $qrMid  = $frame['qr']['y'] + $frame['qr']['size'] / 2;

        expect(abs($qrMid - $inkMid))->toBeLessThan(0.5);
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
