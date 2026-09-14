<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Kukux\DigitalSignature\Pdf\Renderers\PdfRenderer;

/**
 * Writes a placeholder file instead of a real PDF, so session-state tests
 * don't require DomPDF, Imagick or Ghostscript to be installed.
 */
class StubPdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        @mkdir(dirname($destinationPath), 0777, true);
        file_put_contents($destinationPath, "%PDF-1.4\n% stub render of {$view}\n");

        return $destinationPath;
    }

    public static function isAvailable(): bool
    {
        return true;
    }
}
