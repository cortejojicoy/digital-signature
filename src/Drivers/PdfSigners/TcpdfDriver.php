<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\DrawsSignatureStamp;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
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
        $disk   = Storage::disk(config('signature.storage_disk'));
        $inPath = $disk->path($pdfPath);

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

        foreach ($stamps as $stamp) {
            $this->drawStamp(
                $pdf,
                $disk->path($imagePath),
                $stamp['x']      ?? 20,
                $stamp['y']      ?? 250,
                $stamp['width']  ?? 60,
                $stamp['height'] ?? 20,
                $caption,
                $qrPayload,
                $stamp['caption_position'] ?? null,
            );
        }

        $outName = config('signature.signed_docs_path')
            .'/'.pathinfo($pdfPath, PATHINFO_FILENAME)
            .'_signed_'.time().'.pdf';

        $disk->put($outName, $pdf->Output('', 'S'));

        return $outName;
    }
}