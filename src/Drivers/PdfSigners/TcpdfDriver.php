<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\DrawsSignatureCaption;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Illuminate\Support\Facades\Storage;
use TCPDF;

class TcpdfDriver implements PdfSignerDriver
{
    use DrawsSignatureCaption;

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

        // One signature, however many appearances — same rule as the FPDI
        // driver, including the QR belonging only to the first.
        $stamps = array_merge([$position], array_values($extraPositions));

        foreach ($stamps as $index => $stamp) {
            $sigX = $stamp['x']      ?? 20;
            $sigY = $stamp['y']      ?? 250;
            $sigW = $stamp['width']  ?? 60;
            $sigH = $stamp['height'] ?? 20;

            // The caption is carved out of the placement, so the stamp never
            // occupies more of the page than the signatory positioned.
            $captionLayout = $this->layoutCaption($pdf, $caption, $sigW, $sigH);
            $imageH        = $sigH - $captionLayout['height'];

            $pdf->Image($disk->path($imagePath), $sigX, $sigY, $sigW, $imageH);

            $this->drawCaption($pdf, $captionLayout, $sigX, $sigY + $imageH, $sigW);

            if ($index === 0 && $qrPayload !== '') {
                $qrSize = $sigH;
                $qrX    = $sigX + $sigW + 2;
                $qrY    = $sigY;

                $pdf->write2DBarcode(
                    $qrPayload,
                    'QRCODE,H',
                    $qrX,
                    $qrY,
                    $qrSize,
                    $qrSize,
                    ['border' => false, 'padding' => 0],
                );
            }
        }

        $outName = config('signature.signed_docs_path')
            .'/'.pathinfo($pdfPath, PATHINFO_FILENAME)
            .'_signed_'.time().'.pdf';

        $disk->put($outName, $pdf->Output('', 'S'));

        return $outName;
    }
}