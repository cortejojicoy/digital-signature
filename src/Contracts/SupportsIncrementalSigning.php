<?php

namespace Kukux\DigitalSignature\Contracts;

/**
 * Marker for PDF signer drivers that can append a signature to an
 * already-signed document as an ISO 32000 incremental update, leaving every
 * prior signature cryptographically intact.
 *
 * Neither bundled driver implements this, and that is a property of the
 * underlying libraries rather than an oversight: FPDI re-imports and rewrites
 * the whole document, which necessarily invalidates any existing PKCS#7
 * block. Producing a true multi-signature PAdES file in PHP needs a library
 * that can write incremental updates (e.g. SetaPDF-Signer).
 *
 * This is why `signature.multi_signature.mode` defaults to `progressive`
 * rather than `incremental` — see docs/signatory-routing.md, "Signing modes",
 * for exactly what each mode does and does not guarantee.
 */
interface SupportsIncrementalSigning
{
    /**
     * Append a signature to an already-signed PDF without rewriting it.
     *
     * @param  string  $signedPdfPath  Disk-relative path of the PDF that already carries N signatures.
     * @param  array{page:int,x:float,y:float,width:float,height:float}  $position
     * @param  array   $certData       Output of openssl_pkcs12_read() for this signer.
     * @return string  Disk-relative path of the PDF now carrying N+1 signatures.
     */
    public function appendSignature(
        string $signedPdfPath,
        string $imagePath,
        array $position,
        array $certData,
        string $reason,
        string $qrPayload = '',
    ): string;
}
