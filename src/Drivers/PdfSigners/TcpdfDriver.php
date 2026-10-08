<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\DrawsSignatureStamp;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Support\LocalCopy;
use Illuminate\Support\Facades\Storage;
use TCPDF;

class TcpdfDriver implements PdfSignerDriver
{
    use DrawsSignatureStamp;

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

        $pdf = new TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // Embed the cryptographic signature (PAdES-B)
        if (!empty($certData['cert']) && !empty($certData['pkey'])) {
            $pdf->setSignature(
                $certData['cert'],
                $certData['pkey'],
                '',   // extra certs
                '',   // password
                2,    // certification level
                ['Name' => $reason, 'Reason' => $reason],
            );

            // Visible signature appearance
            $pdf->setSignatureAppearance(
                $position['x']      ?? 20,
                $position['y']      ?? 250,
                $position['width']  ?? 60,
                $position['height'] ?? 20,
            );
        }

        // One signature, however many appearances — same layout rules as the
        // FPDI driver, from the same trait.
        $stamps = array_merge([$position], array_values($extraPositions));

        // The image through LocalCopy so an S3 storage disk works (plan A12);
        // on a local disk this is the plain path, as before. Output() runs
        // inside the callback so the temp copy outlives every read of it.
        $bytes = LocalCopy::of($diskName, $imagePath, function (string $imageFsPath) use ($pdf, $stamps, $caption): string {
            foreach ($stamps as $stamp) {
                $this->drawStamp(
                    $pdf,
                    $imageFsPath,
                    $stamp['x']      ?? 20,
                    $stamp['y']      ?? 250,
                    $stamp['width']  ?? 60,
                    $stamp['height'] ?? 20,
                    $caption,
                );
            }

            return $pdf->Output('', 'S');
        });

        $outName = config('signature.signed_docs_path')
            .'/'.pathinfo($pdfPath, PATHINFO_FILENAME)
            .'_signed_'.time().'_'.\Illuminate\Support\Str::random(8).'.pdf';

        $disk->put($outName, $bytes);

        return $outName;
    }
}