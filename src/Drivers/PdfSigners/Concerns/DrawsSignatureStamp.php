<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Concerns;

use TCPDF;

/**
 * Draws one appearance of a signature in the format COA Circular No. 2021-006
 * (IV.C.13) gives as its example: the handwritten signature, and beside it the
 * signatory's full name and the moment of signing in readable form.
 *
 *     ┌──────────────────┬──────────────────────┐
 *     │                  │ Digitally signed     │
 *     │  signature image │ by Juan DelaCruz     │
 *     │                  │ Date: 2020.05.21     │
 *     │                  │ 19:37:33 +08'00'     │
 *     └──────────────────┴──────────────────────┘
 *
 * **Only the ink scales.** The text block is drawn at a fixed size, so the
 * name reads the same on every stamp in the document whatever size each box
 * was drawn at. Enlarging a placement enlarges the signature and nothing else.
 *
 * Everything fits inside the placement rectangle: that rectangle is where the
 * signatory said their signature goes, and anything outside it belongs to the
 * form. The text only shrinks below its configured size when the box cannot
 * physically hold it — and never below `min_font_pt`, and never by cutting the
 * name, because the full name is what the circular requires.
 *
 * The layout lives in one place, because two drivers doing this arithmetic
 * separately is two chances to disagree about where a signature goes. Its
 * browser half is `resources/js/utils/stampLayout.js`.
 */
trait DrawsSignatureStamp
{
    /**
     * Draw a complete stamp with its top-left corner at ($x, $y).
     *
     * @param  array<int, string>  $captionLines
     */
    protected function drawStamp(
        TCPDF $pdf,
        string $imageFsPath,
        float $x,
        float $y,
        float $width,
        float $height,
        array $captionLines = [],
    ): void {
        $frame = $this->stampFrame(
            $pdf,
            $width,
            $height,
            $captionLines,
            $this->imageAspect($imageFsPath),
        );

        $pdf->Image(
            $imageFsPath,
            $x + $frame['image']['x'],
            $y + $frame['image']['y'],
            $frame['image']['w'],
            $frame['image']['h'],
            'PNG',
        );

        $this->drawCaption($pdf, $frame['caption'], $x, $y);
    }

    /**
     * Divide the placement between the ink and the text block beside it.
     *
     * The text takes its natural width off the right; the ink is fitted, at
     * its own proportions, into what is left. The two are then centred as one
     * group so the name stays right next to the signature it belongs to at
     * every box size, rather than drifting to the far edge as the box grows.
     *
     * All coordinates are relative to the box's own top-left corner.
     *
     * @param  array<int, string>  $lines
     * @param  float|null  $imageAspect  Natural width/height of the signature.
     * @return array{image: array{x:float,y:float,w:float,h:float}, caption: array<string, mixed>}
     */
    protected function stampFrame(
        TCPDF $pdf,
        float $width,
        float $height,
        array $lines,
        ?float $imageAspect = null,
    ): array {
        $caption = $this->layoutCaption($pdf, $lines, $width, $height);

        $gap      = $caption['lines'] !== [] ? $this->captionGap() : 0.0;
        $inkAreaW = max(0.0, $width - $caption['width'] - $gap);
        $ink      = $this->fitWithin($imageAspect, $inkAreaW, $height);

        $groupW = $ink['w'] + $gap + $caption['width'];
        $left   = max(0.0, ($width - $groupW) / 2);

        return [
            'image' => [
                'x' => $left,
                'y' => max(0.0, ($height - $ink['h']) / 2),
                'w' => $ink['w'],
                'h' => $ink['h'],
            ],
            'caption' => $caption + [
                'x' => $left + $ink['w'] + $gap,
                'y' => max(0.0, ($height - $caption['height']) / 2),
                'w' => $caption['width'],
            ],
        ];
    }

    /**
     * The largest rectangle of the given proportions that fits the space.
     *
     * With no aspect to honour — an unreadable file, a caller that did not
     * supply one — the space is used as-is.
     *
     * @return array{w: float, h: float}
     */
    protected function fitWithin(?float $aspect, float $maxWidth, float $maxHeight): array
    {
        if ($aspect === null || $aspect <= 0.0) {
            return ['w' => $maxWidth, 'h' => $maxHeight];
        }

        $width = min($maxWidth, $maxHeight * $aspect);

        return ['w' => $width, 'h' => $width / $aspect];
    }

    /**
     * Proportions of the signature image, or null when they cannot be read.
     *
     * Cached per path: one signature is commonly stamped in several places on
     * the same document, and re-reading the file's header for each appearance
     * is work with a known answer.
     */
    protected function imageAspect(string $path): ?float
    {
        static $cache = [];

        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        $size = @getimagesize($path);

        return $cache[$path] = ($size && ! empty($size[1]))
            ? (float) $size[0] / (float) $size[1]
            : null;
    }

    /**
     * Size the text block beside the signature.
     *
     * Drawn at `font_pt` regardless of the box — that is what keeps the name
     * the same size on every stamp. It steps down only when the box cannot
     * hold the block next to a usable sliver of ink, and stops at
     * `min_font_pt`. Lines are never truncated: the full name is the point.
     *
     * An empty `lines` means "draw no text".
     *
     * @param  array<int, string>  $lines
     * @return array{lines: array<int, string>, size: float, height: float, width: float}
     */
    protected function layoutCaption(TCPDF $pdf, array $lines, float $boxWidth, float $boxHeight): array
    {
        $none = ['lines' => [], 'size' => 0.0, 'height' => 0.0, 'width' => 0.0];

        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        if ($lines === [] || ! config('signature.caption.enabled', true)) {
            return $none;
        }

        $size    = (float) config('signature.caption.font_pt', 7);
        $minSize = min($size, (float) config('signature.caption.min_font_pt', 4));

        // The text may crowd the ink, but not erase it.
        $maxWidth = $boxWidth - $this->captionGap() - $boxWidth * 0.25;

        for (; $size >= $minSize; $size -= 0.25) {
            $block = $this->measureCaption($pdf, $lines, $size);

            if ($block['width'] <= $maxWidth && $block['height'] <= $boxHeight) {
                return ['lines' => $lines] + $block;
            }
        }

        // An undersized box. Still draw the whole name at the smallest size;
        // a stamp that names its signatory slightly outside a cramped box is
        // better than one that does not name them.
        return ['lines' => $lines] + $this->measureCaption($pdf, $lines, $minSize);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{size: float, height: float, width: float}
     */
    protected function measureCaption(TCPDF $pdf, array $lines, float $size): array
    {
        $widest = 0.0;

        foreach ($lines as $line) {
            $widest = max($widest, (float) $pdf->GetStringWidth($line, 'helvetica', '', $size));
        }

        return [
            'size'   => $size,
            'height' => count($lines) * $size * $this->lineHeightRatio(),
            'width'  => $widest,
        ];
    }

    /**
     * Draw the text block stampFrame() positioned, offset by the box's own
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

        // No cell padding: the block was measured as bare text, and padding
        // would push the last characters of a long name past its edge.
        $padding = $pdf->getCellPaddings();
        $pdf->setCellPaddings(0, 0, 0, 0);

        $pdf->SetFont('helvetica', '', $caption['size']);
        $pdf->SetTextColor(...$this->captionColour());

        foreach ($caption['lines'] as $index => $line) {
            $pdf->SetXY($originX + $caption['x'], $originY + $caption['y'] + ($index * $lineHeight));
            $pdf->Cell($caption['w'], $lineHeight, $line, 0, 0, 'L');
        }

        // Leave the document as it was found: anything drawn after this — a
        // second stamp on the same page — would otherwise inherit both.
        $pdf->SetTextColor(0, 0, 0);
        $pdf->setCellPaddings($padding['L'], $padding['T'], $padding['R'], $padding['B']);
    }

    private function captionGap(): float
    {
        return max(0.0, (float) config('signature.caption.gap', 3));
    }

    private function lineHeightRatio(): float
    {
        return max(1.0, (float) config('signature.caption.line_height', 1.15));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function captionColour(): array
    {
        $configured = config('signature.caption.color', [0, 0, 0]);

        if (! is_array($configured) || count($configured) !== 3) {
            return [0, 0, 0];
        }

        return array_map(fn ($channel): int => max(0, min(255, (int) $channel)), array_values($configured));
    }
}
