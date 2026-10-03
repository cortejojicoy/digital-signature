<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Kukux\DigitalSignature\Pdf\SlotAnchors;

/** A view that marks its signature spaces, but lost the reviewer's. */
class MarksApplicantOnlyRenderer extends StubPdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        parent::render($view, $data, $destinationPath);

        SlotAnchors::write($destinationPath, [
            'applicant' => ['page' => 1, 'x' => 60.0, 'y' => 120.0, 'width' => 150.0, 'height' => 40.0],
        ]);

        return $destinationPath;
    }
}
