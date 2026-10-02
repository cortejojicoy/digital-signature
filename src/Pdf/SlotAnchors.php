<?php

namespace Kukux\DigitalSignature\Pdf;

use Closure;

/**
 * Where each signature slot actually landed in a rendered PDF.
 *
 * A fixed placement (page 2, 130pt up) only works for a document whose
 * layout never moves. A report that flows (one task or three hundred) puts
 * its signature block on page 2 one day and page 87 the next. So the Blade
 * marks each signature space:
 *
 *     <div data-signature-slot="prepared_by" style="height: 42pt"></div>
 *
 * and the renderer records the box DomPDF drew it in. The result is saved
 * next to the PDF ("<file>.slots.json") and used when a signing session
 * opens, ahead of the designer's placement.
 *
 * Coordinates are PDF points, y from the BOTTOM of the page, like every
 * other placement in the package.
 */
final class SlotAnchors
{
    public const ATTRIBUTE = 'data-signature-slot';

    /**
     * Start recording on a Dompdf instance. Call before it renders; the
     * returned closure gives the anchors once it has.
     *
     * @param  object  $dompdf  a \Dompdf\Dompdf (untyped: dompdf is optional for this package)
     * @return Closure(): array<string, array{page: int, x: float, y: float, width: float, height: float}>
     */
    public static function record(object $dompdf): Closure
    {
        $found = [];

        // setCallbacks() replaces what's there, so carry existing ones over.
        $callbacks = [];

        foreach ($dompdf->getCallbacks() as $event => $functions) {
            foreach ($functions as $f) {
                $callbacks[] = ['event' => $event, 'f' => $f];
            }
        }

        $callbacks[] = [
            'event' => 'end_frame',
            'f' => function ($frame, $canvas) use (&$found): void {
                $node = $frame->get_node();

                if (! $node instanceof \DOMElement || ! $node->hasAttribute(self::ATTRIBUTE)) {
                    return;
                }

                $slot = trim($node->getAttribute(self::ATTRIBUTE));

                // An element split across a page break is drawn twice; the
                // first piece is where it starts.
                if ($slot === '' || isset($found[$slot])) {
                    return;
                }

                $box = $frame->get_border_box();
                [$x, $top, $width, $height] = [(float) $box['x'], (float) $box['y'], (float) $box['w'], (float) $box['h']];

                $found[$slot] = [
                    'page'   => (int) $canvas->get_page_number(),
                    'x'      => round($x, 2),
                    'y'      => round((float) $canvas->get_height() - $top - $height, 2),
                    'width'  => round($width, 2),
                    'height' => round($height, 2),
                ];
            },
        ];

        $dompdf->setCallbacks($callbacks);

        return function () use (&$found): array {
            return $found;
        };
    }

    public static function sidecar(string $pdfPath): string
    {
        return $pdfPath.'.slots.json';
    }

    /** @param  array<string, array<string, int|float>>  $anchors */
    public static function write(string $pdfPath, array $anchors): void
    {
        file_put_contents(self::sidecar($pdfPath), json_encode($anchors, JSON_PRETTY_PRINT));
    }

    /**
     * The anchors saved for a PDF, or [] when it was rendered without any.
     *
     * @return array<string, array{page: int, x: float, y: float, width: float, height: float}>
     */
    public static function read(string $pdfPath): array
    {
        $path = self::sidecar($pdfPath);

        if (! is_file($path)) {
            return [];
        }

        $anchors = json_decode((string) file_get_contents($path), true);

        return is_array($anchors) ? $anchors : [];
    }
}
