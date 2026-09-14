<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Concerns;

use TCPDF;

/**
 * Draws the small block of human-readable provenance under a signature image.
 *
 * The package already binds this information to the signature cryptographically
 * — HMAC-signed tEXt and XMP chunks inside the PNG, a PKCS#7 block in the PDF,
 * a QR alongside. All of it is invisible to somebody holding a printout. The
 * caption is the part a person can read: who signed, when, and the reference to
 * quote if they want it checked.
 *
 * **It never grows the stamp.** The rectangle came from a signatory dropping
 * their signature onto a form, sized to the line it belongs on; spilling out of
 * it would land on whatever the form put underneath. So the caption is carved
 * out of the bottom of that rectangle and the image shrinks to fit the rest —
 * which is also why there is a floor below which the caption is skipped
 * entirely. A signature too small to read is worse than one with no caption.
 */
trait DrawsSignatureCaption
{
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
            $lineHeight = $size * 1.18;

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
        $lineHeight = $minSize * 1.18;
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
     * Draw the laid-out caption with its top edge at $y.
     *
     * @param  array{lines: array<int, string>, size: float, height: float}  $caption
     */
    protected function drawCaption(TCPDF $pdf, array $caption, float $x, float $y, float $width): void
    {
        if ($caption['lines'] === []) {
            return;
        }

        $lineHeight = $caption['size'] * 1.18;

        $pdf->SetFont('helvetica', '', $caption['size']);
        $pdf->SetTextColor(...$this->captionColour());

        foreach ($caption['lines'] as $index => $line) {
            $pdf->SetXY($x, $y + ($index * $lineHeight));
            $pdf->Cell($width, $lineHeight, $line, 0, 0, 'L');
        }

        // Leave the document as it was found: anything drawn after this — a
        // second stamp on the same page — would otherwise inherit 4pt grey.
        $pdf->SetTextColor(0, 0, 0);
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
