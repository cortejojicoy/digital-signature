<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Kukux\DigitalSignature\Pdf\SlotAnchors;

/**
 * A stub render whose "Noted by" line landed on page 7, as a long report's
 * would: what SlotAnchors records for a real DomPDF render.
 */
class AnchoringStubRenderer extends StubPdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        parent::render($view, $data, $destinationPath);

        SlotAnchors::write($destinationPath, [
            'noted_by' => ['page' => 7, 'x' => 380.5, 'y' => 212.25, 'width' => 150.0, 'height' => 42.0],
            'not_a_slot' => ['page' => 1, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ]);

        return $destinationPath;
    }
}
