<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Contracts;

interface PdfSignerDriver
{
    /**
     * Embed the signature image and PAdES block into the PDF.
     * Returns the path to the signed PDF on the configured disk.
     *
     * @param  string  $qrPayload  No longer sent — the stamp carries no QR
     *   (COA Circular 2021-006 IV.C.13 asks for the name and the handwritten
     *   signature only). Kept so existing driver implementations still match.
     *
     * @param  array<int, string>  $caption  Human-readable lines drawn beside
     *   the signature image: "Digitally signed", "by {full name}" and the date
     *   and time of signing. Carved out of the placement rectangle rather than
     *   added to it, because that rectangle was sized to fit a line on a form.
     *   Defaults to empty so a host's own driver keeps working unchanged.
     *
     * @param  array<int, array{page?:int,x?:float,y?:float,width?:float,height?:float}>  $extraPositions
     *   Further places to draw the SAME signature. A form that asks one person
     *   to sign in three places is one act of signing with three appearances:
     *   one Signature row, one PKCS#7 block over the whole document, and a
     *   stamp at each of these. Empty for the ordinary single-stamp case.
     */
    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason,
        string $qrPayload = '',
        array  $caption = [],
        array  $extraPositions = [],
    ): string;
}
