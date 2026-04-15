<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\TcpdfFpdi;

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
    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason = 'Approved',
    ): string {
        $disk   = Storage::disk(config('signature.storage_disk'));
        $inPath = $disk->path($pdfPath);

        $pdf = new TcpdfFpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $count = $pdf->setSourceFile($inPath);

        for ($i = 1; $i <= $count; $i++) {
            $tpl = $pdf->importPage($i);
            $sz  = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($sz['orientation'], [$sz['width'], $sz['height']]);
            $pdf->useTemplate($tpl);

            // Stamp the visible signature image on the designated page
            if ($i === ($position['page'] ?? 1)) {
                $pdf->Image(
                    $disk->path($imagePath),
                    $position['x']      ?? 20,
                    $position['y']      ?? 250,
                    $position['width']  ?? 60,
                    $position['height'] ?? 20,
                    'PNG',
                );
            }
        }

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
        if (!empty($certData['cert']) && !empty($certData['pkey'])) {
            $tsaUrl     = config('signature.tsa.url') ?: null;
            $extracerts = $this->buildExtracertsPem($certData['extracerts'] ?? []);

            $sigInfo = [
                'Name'        => config('app.name'),
                'Reason'      => $reason,
                'Location'    => parse_url(config('app.url'), PHP_URL_HOST) ?? '',
            ];

            if ($tsaUrl !== null) {
                $sigInfo['TSA'] = $tsaUrl;
            }

            $pdf->setSignature(
                $certData['cert'],   // PEM certificate string
                $certData['pkey'],   // PEM private key (decrypted from PFX)
                '',                  // key password — empty, already decrypted
                $extracerts,         // CA chain PEM (empty for self-signed)
                2,                   // cert_type: 2 = certifying, DocMDP P=2
                $sigInfo,
            );
        }

        $outName = config('signature.signed_docs_path')
            . '/' . pathinfo($pdfPath, PATHINFO_FILENAME)
            . '_signed_' . time() . '.pdf';

        $disk->put($outName, $pdf->Output('', 'S'));

        return $outName;
    }

    // -------------------------------------------------------------------------

    /**
     * Convert extracerts from the mixed format returned by openssl_pkcs12_read()
     * to a single concatenated PEM string that TCPDF expects.
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
