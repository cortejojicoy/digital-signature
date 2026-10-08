<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\StampsImportedPages;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Support\LocalCopy;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\TcpdfFpdi;
use TCPDF;

/**
 * PDF signer that:
 *   1. Imports all pages of the original PDF via FPDI
 *   2. Stamps the signature image at the configured coordinates
 *   3. Embeds a real PKCS#7 / PAdES cryptographic signature using TCPDF's
 *      setSignature() — this is what PDF readers verify, not just a visual stamp
 *   4. Sets DocMDP P=2 so the PDF certifies that only form fields and additional
 *      signatures are permitted; any other modification is detectable
 *   5. Optionally attaches an RFC 3161 trusted timestamp via a TSA endpoint
 *
 * Security parity with LibreSign:
 *   - PKCS#7 detached signature (equivalent to LibreSign's Pkcs7Handler)
 *   - DocMDP permission level (equivalent to LibreSign's DocMdpHandler)
 *   - TSA timestamp support (equivalent to LibreSign's TsaValidationService)
 */
class FpdiDriver implements PdfSignerDriver
{
    // The import-and-stamp half is shared with DeferredPdfSigner; it brings
    // DrawsSignatureStamp with it.
    use StampsImportedPages;

    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason = 'Approved',
        string $qrPayload = '',
        array  $caption = [],
        array  $extraPositions = [],
    ): string {
        $diskName = config('signature.storage_disk');
        $disk     = Storage::disk($diskName);

        // One signature, however many appearances.
        $stamps = array_merge([$position], array_values($extraPositions));

        $extracertsFile = null;

        try {
            // Both files through LocalCopy, so an S3 storage disk (RustFS,
            // plan A12) works; on a local disk these are the plain paths, as
            // before. Everything up to Output() stays inside the callbacks:
            // FPDI keeps reading the source until the document is closed.
            $bytes = LocalCopy::of($diskName, $pdfPath, function (string $inPath) use ($diskName, $imagePath, $stamps, $caption, $certData, $reason, &$extracertsFile): string {
                return LocalCopy::of($diskName, $imagePath, function (string $imageFsPath) use ($inPath, $stamps, $caption, $certData, $reason, &$extracertsFile): string {
                    // 'pt' — placements are PDF points everywhere else in this package
                    // (SlotDefinition, the designer, signature_positions), and TCPDF
                    // otherwise defaults to MILLIMETRES. Left on the default, a y of 389
                    // points was drawn as 389mm down a 297mm page: the stamp fell off the
                    // bottom and TCPDF's auto page break silently manufactured blank pages
                    // to hold it.
                    $pdf = $this->importAndStamp(new TcpdfFpdi('P', 'pt'), $inPath, $imageFsPath, $stamps, $caption);

                    $extracertsFile = $this->applySignature($pdf, $certData, $reason);

                    return $pdf->Output('', 'S');
                });
            });

            $outName = config('signature.signed_docs_path')
                . '/' . pathinfo($pdfPath, PATHINFO_FILENAME)
                . '_signed_' . time() . '_' . \Illuminate\Support\Str::random(8) . '.pdf';

            $disk->put($outName, $bytes);
        } finally {
            // The chain file only has to outlive Output(), which is where
            // TCPDF actually runs openssl_pkcs7_sign().
            if ($extracertsFile !== null) {
                @unlink($extracertsFile);
            }
        }

        return $outName;
    }

    /**
     * Set up TCPDF's signature, if there is a key to sign with. Returns the
     * temporary chain file the caller must delete after Output(), or null.
     */
    private function applySignature(TCPDF $pdf, array $certData, string $reason): ?string
    {
        // ------------------------------------------------------------------
        // Cryptographic PKCS#7 signature
        //
        // $certData comes from openssl_pkcs12_read(), which already decrypts
        // the private key — so the password passed to setSignature() is empty.
        //
        // cert_type = 2  →  DocMDP P=2: certifies the document and allows only
        //   form-field changes and additional signatures (ISO 32000-1 §12.8.2.2).
        //   Any structural modification after signing is detectable by PDF readers.
        // ------------------------------------------------------------------
        if (empty($certData['cert']) || empty($certData['pkey'])) {
            return null;
        }

        $tsaUrl = config('signature.tsa.url') ?: null;

        // TCPDF hands `extracerts` straight to openssl_pkcs7_sign()'s
        // $untrusted_certificates_filename, which is a PATH, not PEM
        // content. Passing the chain inline fails with "Error opening the
        // file, -----BEGIN CERTIFICATE-----". Self-signed certificates have
        // no chain, which is why this only bites once a real CA is
        // configured.
        $extracertsFile = $this->writeExtracertsFile($certData['extracerts'] ?? []);

        $sigInfo = [
            'Name'        => config('app.name'),
            'Reason'      => $reason,
            'Location'    => parse_url(config('app.url'), PHP_URL_HOST) ?? '',
        ];

        if ($tsaUrl !== null) {
            $sigInfo['TSA'] = $tsaUrl;
        }

        $pdf->setSignature(
            $certData['cert'],      // PEM certificate string
            $certData['pkey'],      // PEM private key (decrypted from PFX)
            '',                     // key password — empty, already decrypted
            $extracertsFile ?? '',  // CA chain FILE (empty for self-signed)
            2,                      // cert_type: 2 = certifying, DocMDP P=2
            $sigInfo,
        );

        return $extracertsFile;
    }

    // -------------------------------------------------------------------------

    /**
     * Write the CA chain to a temporary PEM file and return its path, or null
     * when there is no chain to write.
     *
     * A file rather than a string because that is what openssl_pkcs7_sign()
     * takes — see the call site. The caller deletes it once Output() has run.
     */
    private function writeExtracertsFile(array|string $extracerts): ?string
    {
        $pem = $this->buildExtracertsPem($extracerts);

        if (trim($pem) === '') {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'sigchain');

        if ($path === false || file_put_contents($path, $pem) === false) {
            throw new \RuntimeException('Could not write the certificate chain to a temporary file.');
        }

        return $path;
    }

    /**
     * Convert extracerts from the mixed format returned by openssl_pkcs12_read()
     * into one concatenated PEM string.
     */
    private function buildExtracertsPem(array|string $extracerts): string
    {
        if (is_string($extracerts)) {
            return $extracerts;
        }

        $pem = '';
        foreach ($extracerts as $extra) {
            openssl_x509_export($extra, $buf);
            $pem .= $buf;
        }

        return $pem;
    }
}
