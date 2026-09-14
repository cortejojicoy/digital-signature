<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Contracts;

interface PdfSignerDriver
{
    /**
     * Embed the signature image and PAdES block into the PDF.
     * Returns the path to the signed PDF on the configured disk.
     *
     * @param  array<int, string>  $caption  Human-readable provenance drawn
     *   under the signature image — who signed, when, and the reference to
     *   quote. Carved out of the placement rectangle rather than added to it,
     *   because that rectangle was sized to fit a line on a form. Defaults to
     *   empty so a host's own driver implementation keeps working unchanged.
     */
    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason,
        string $qrPayload = '',
        array  $caption = [],
    ): string;
}
