<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Contracts;

interface PdfSignerDriver
{
    /**
     * Embed the signature image and PAdES block into the PDF.
     * Returns the path to the signed PDF on the configured disk.
     */
    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason,
    ): string;
}
