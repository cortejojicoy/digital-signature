<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Concerns;

use TCPDF;

/**
 * Draws one appearance of a signature: the ink, a verification QR, and the
 * small block of readable provenance under them.
 *
 * **Everything fits inside the placement rectangle.** That rectangle is where a
 * signatory dropped their signature, sized to the line it belongs on; anything
 * outside it belongs to the form. The QR used to be drawn *beside* the box,
 * which put it wherever the form happened to have content — so it is now carved
 * out of the right-hand side, exactly as the caption is carved off the bottom.
 *
 * The layout, in one place, because two drivers doing this arithmetic
 * separately is two chances to disagree about where a signature goes:
 *
 *     ┌──────────────────────────┬──────┐
 *     │  signature image         │  QR  │
 *     ├──────────────────────────┴──────┤
 *     │  Name · Signed … · Ref …        │
 *     └─────────────────────────────────┘
 *
 * Both extras stand down on a box too small to carry them. A signature
 * squeezed into nothing is worse than one without a caption, and a QR too
 * small to scan is worse than no QR at all.
 */
trait DrawsSignatureStamp
{
    /**
     * Draw a complete stamp with its top-left corner at ($x, $y).
     *
     * @param  array<int, string>  $captionLines
     * @param  string  $captionPosition  bottom | top | left | right
     */
    protected function drawStamp(
        TCPDF $pdf,
        string $imageFsPath,
        float $x,
        float $y,
        float $width,
        float $height,
        array $captionLines = [],
        string $qrPayload = '',
        ?string $captionPosition = null,
    ): void {
        $frame = $this->stampFrame(
            $pdf,
            $width,
            $height,
            $captionLines,
            $qrPayload !== '',
            $captionPosition ?? (string) config('signature.caption.position', 'bottom'),
        );

        $pdf->Image(
            $imageFsPath,
            $x + $frame['image']['x'],
            $y + $frame['image']['y'],
            $frame['image']['w'],
            $frame['image']['h'],
            'PNG',
        );

        if ($frame['qr'] !== null) {
            $pdf->write2DBarcode(
                $qrPayload,
                'QRCODE,M',
                $x + $frame['qr']['x'],
                $y + $frame['qr']['y'],
                $frame['qr']['size'],
                $frame['qr']['size'],
                ['border' => false, 'padding' => 0],
            );
        }

        $this->drawCaption($pdf, $frame['caption'], $x, $y);
    }

    /**
     * Divide the placement between the caption band, the QR and the ink.
     *
     * The caption takes one whole side of the box — which side is the
     * signatory's choice, because a form dictates it: a signature line with
     * the printed name underneath has no room below and plenty beside it,
     * while one at the foot of a page has the opposite problem. Everything
     * else fits into what is left.
     *
     * All coordinates are relative to the box's own top-left corner, so the
     * caller adds its origin once and no arithmetic is repeated per element.
     *
     * @param  array<int, string>  $lines
     * @return array{image: array{x:float,y:float,w:float,h:float}, qr: array{x:float,y:float,size:float}|null, caption: array<string, mixed>}
     */
    protected function stampFrame(
        TCPDF $pdf,
        float $width,
        float $height,
        array $lines,
        bool $wantQr,
        string $position = 'bottom',
    ): array {
        $position = in_array($position, ['bottom', 'top', 'left', 'right'], true) ? $position : 'bottom';
        $vertical = $position === 'left' || $position === 'right';

        $caption = $vertical
            ? $this->layoutCaptionColumn($pdf, $lines, $width, $height)
            : $this->layoutCaption($pdf, $lines, $width, $height);

        // What the caption did not claim.
        $band    = $vertical ? $caption['width'] ?? 0.0 : $caption['height'];
        $content = match ($position) {
            'bottom' => ['x' => 0.0,   'y' => 0.0,   'w' => $width,        'h' => $height - $band],
            'top'    => ['x' => 0.0,   'y' => $band, 'w' => $width,        'h' => $height - $band],
            'left'   => ['x' => $band, 'y' => 0.0,   'w' => $width - $band, 'h' => $height],
            'right'  => ['x' => 0.0,   'y' => 0.0,   'w' => $width - $band, 'h' => $height],
        };

        $captionBox = match ($position) {
            'bottom' => ['x' => 0.0, 'y' => $height - $band, 'w' => $width],
            'top'    => ['x' => 0.0, 'y' => 0.0,             'w' => $width],
            'left'   => ['x' => 0.0, 'y' => 0.0,             'w' => $band],
            'right'  => ['x' => $width - $band, 'y' => 0.0,  'w' => $band],
        };

        // Vertically centre a column caption against the ink it belongs to.
        if ($vertical && $caption['lines'] !== []) {
            $captionBox['y'] = max(0.0, ($height - $caption['height']) / 2);
        }

        $qrSize = $this->qrSize($content['w'], $content['h'], $wantQr);
        $gap    = $qrSize > 0 ? $this->qrGap() : 0.0;

        return [
            'image' => [
                'x' => $content['x'],
                'y' => $content['y'],
                'w' => $content['w'] - ($qrSize > 0 ? $qrSize + $gap : 0),
                'h' => $content['h'],
            ],
            'qr' => $qrSize > 0
                ? [
                    'x'    => $content['x'] + $content['w'] - $qrSize,
                    'y'    => $content['y'],
                    'size' => $qrSize,
                ]
                : null,
            'caption' => $caption + $captionBox,
        ];
    }

    /**
     * How big the verification QR may be, or 0 for "not on this stamp".
     *
     * Measured against the CONTENT area, not the whole box — a caption down
     * one side has already taken its share, and sizing the QR against the
     * original width would push it over the text.
     *
     * Square, and refused outright below a size no phone will decode: an
     * unreadable barcode on a legal document is a promise the document cannot
     * keep.
     */
    protected function qrSize(float $width, float $height, bool $wanted): float
    {
        if (! $wanted || ! config('signature.qr.enabled', true)) {
            return 0.0;
        }

        $min = (float) config('signature.qr.min_size', 26);
        $max = (float) config('signature.qr.max_size', 48);

        // Never more than the content's own height, nor more than a third of
        // its width — past that the signature stops being the main mark.
        $size = min($height, $width / 3, $max);

        return $size >= $min ? $size : 0.0;
    }

    protected function qrGap(): float
    {
        return (float) config('signature.qr.gap', 2);
    }

    /**
     * Work out how much of the stamp the caption may take, and at what size.
     *
     * Returns the lines that actually fit, the font size to draw them at, and
     * the height to reserve. An empty `lines` means "draw no caption" — the
     * box is too short, or there was nothing to say.
     *
     * @param  array<int, string>  $lines
     * @return array{lines: array<int, string>, size: float, height: float}
     */
    protected function layoutCaption(TCPDF $pdf, array $lines, float $boxWidth, float $boxHeight): array
    {
        $none = ['lines' => [], 'size' => 0.0, 'height' => 0.0];

        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        if ($lines === [] || ! config('signature.caption.enabled', true)) {
            return $none;
        }

        // Below this the signature itself stops being legible once anything is
        // taken off it, so the caption stands down rather than ruining both.
        if ($boxHeight < (float) config('signature.caption.min_box_height', 28)) {
            return $none;
        }

        $maxSize = (float) config('signature.caption.max_font_pt', 6);
        $minSize = (float) config('signature.caption.min_font_pt', 4);
        $ratio   = (float) config('signature.caption.height_ratio', 0.38);

        $available = $boxHeight * max(0.1, min(0.9, $ratio));

        // Largest size that fits both the band and the widest line. Stepping
        // down in quarter points rather than solving it directly keeps this
        // readable, and the range is a couple of points wide.
        for ($size = $maxSize; $size >= $minSize; $size -= 0.25) {
            $lineHeight = $size * $this->lineHeightRatio();

            $fitting = (int) floor($available / $lineHeight);

            if ($fitting < 1) {
                continue;
            }

            $candidate = array_slice($lines, 0, $fitting);
            $widest = 0.0;

            foreach ($candidate as $line) {
                $widest = max($widest, (float) $pdf->GetStringWidth($line, 'helvetica', '', $size));
            }

            if ($widest <= $boxWidth) {
                return [
                    'lines'  => $candidate,
                    'size'   => $size,
                    'height' => count($candidate) * $lineHeight,
                ];
            }
        }

        // Nothing fit cleanly at any size. Rather than drop the provenance
        // altogether, draw as much of it as the box can hold at the smallest
        // size and let the ellipsis say the rest was cut.
        $lineHeight = $minSize * $this->lineHeightRatio();
        $fitting    = (int) floor($available / $lineHeight);

        if ($fitting < 1) {
            return $none;
        }

        $candidate = array_map(
            fn (string $line): string => $this->truncateToWidth($pdf, $line, $boxWidth, $minSize),
            array_slice($lines, 0, $fitting),
        );

        return [
            'lines'  => $candidate,
            'size'   => $minSize,
            'height' => count($candidate) * $lineHeight,
        ];
    }

    /**
     * The same caption, stacked down a column beside the signature.
     *
     * Sized against the band's WIDTH rather than the box's, because that is
     * the constraint here — a column is always narrow, and a line that fits
     * the whole box tells you nothing about whether it fits the strip it is
     * actually going in.
     *
     * @param  array<int, string>  $lines
     * @return array{lines: array<int, string>, size: float, height: float, width: float}
     */
    protected function layoutCaptionColumn(TCPDF $pdf, array $lines, float $boxWidth, float $boxHeight): array
    {
        $none = ['lines' => [], 'size' => 0.0, 'height' => 0.0, 'width' => 0.0];

        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        if ($lines === [] || ! config('signature.caption.enabled', true)) {
            return $none;
        }

        // Beside the signature the limit is horizontal: too narrow a box and
        // the ink is left with a sliver rather than the caption going unread.
        if ($boxWidth < (float) config('signature.caption.min_box_width', 110)) {
            return $none;
        }

        $maxSize = (float) config('signature.caption.max_font_pt', 6);
        $minSize = (float) config('signature.caption.min_font_pt', 4);
        $ratio   = max(0.1, min(0.9, (float) config('signature.caption.width_ratio', 0.42)));

        $band = $boxWidth * $ratio;

        for ($size = $maxSize; $size >= $minSize; $size -= 0.25) {
            $lineHeight = $size * $this->lineHeightRatio();

            if (count($lines) * $lineHeight > $boxHeight) {
                continue;
            }

            $widest = 0.0;
            foreach ($lines as $line) {
                $widest = max($widest, (float) $pdf->GetStringWidth($line, 'helvetica', '', $size));
            }

            if ($widest <= $band) {
                return [
                    'lines'  => $lines,
                    'size'   => $size,
                    'height' => count($lines) * $lineHeight,
                    'width'  => $band,
                ];
            }
        }

        // Truncate rather than abandon the provenance, as the bottom band does.
        $lineHeight = $minSize * $this->lineHeightRatio();
        $fitting    = (int) floor($boxHeight / $lineHeight);

        if ($fitting < 1) {
            return $none;
        }

        $candidate = array_map(
            fn (string $line): string => $this->truncateToWidth($pdf, $line, $band, $minSize),
            array_slice($lines, 0, $fitting),
        );

        return [
            'lines'  => $candidate,
            'size'   => $minSize,
            'height' => count($candidate) * $lineHeight,
            'width'  => $band,
        ];
    }

    /**
     * Draw the caption block stampFrame() positioned, offset by the box's own
     * origin.
     *
     * @param  array<string, mixed>  $caption
     */
    protected function drawCaption(TCPDF $pdf, array $caption, float $originX, float $originY): void
    {
        if (($caption['lines'] ?? []) === []) {
            return;
        }

        $lineHeight = $caption['size'] * $this->lineHeightRatio();
        $align      = $this->captionAlign();

        $pdf->SetFont('helvetica', '', $caption['size']);
        $pdf->SetTextColor(...$this->captionColour());

        foreach ($caption['lines'] as $index => $line) {
            $pdf->SetXY($originX + $caption['x'], $originY + $caption['y'] + ($index * $lineHeight));
            $pdf->Cell($caption['w'], $lineHeight, $line, 0, 0, $align);
        }

        // Leave the document as it was found: anything drawn after this — a
        // second stamp on the same page — would otherwise inherit 4pt grey.
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Leading as a multiple of the font size.
     *
     * Tight on purpose. The caption is a block of provenance attached to the
     * signature above it, and loose leading makes it read as a separate note
     * floating in whatever the form has underneath.
     */
    private function lineHeightRatio(): float
    {
        return max(1.0, (float) config('signature.caption.line_height', 1.06));
    }

    private function captionAlign(): string
    {
        $align = strtoupper((string) config('signature.caption.align', 'C'));

        return in_array($align, ['L', 'C', 'R'], true) ? $align : 'C';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function captionColour(): array
    {
        $configured = config('signature.caption.color', [90, 90, 90]);

        if (! is_array($configured) || count($configured) !== 3) {
            return [90, 90, 90];
        }

        return array_map(fn ($channel): int => max(0, min(255, (int) $channel)), array_values($configured));
    }

    private function truncateToWidth(TCPDF $pdf, string $line, float $width, float $size): string
    {
        if ((float) $pdf->GetStringWidth($line, 'helvetica', '', $size) <= $width) {
            return $line;
        }

        // mb_* throughout: a signer's name is as likely to be "José Ramírez"
        // as not, and cutting a multi-byte name mid-character would render as
        // a replacement glyph in the middle of the provenance line.
        $truncated = $line;

        while ($truncated !== ''
            && (float) $pdf->GetStringWidth($truncated.'…', 'helvetica', '', $size) > $width) {
            $truncated = mb_substr($truncated, 0, mb_strlen($truncated) - 1);
        }

        return $truncated === '' ? '' : $truncated.'…';
    }
}
