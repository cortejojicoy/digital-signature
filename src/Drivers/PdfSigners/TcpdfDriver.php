<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Illuminate\Support\Facades\Storage;
use TCPDF;

class TcpdfDriver implements PdfSignerDriver
{
    public function sign(
        string $pdfPath,
        string $imagePath,
        array  $position,
        array  $certData,
        string $reason = 'Approved',
        string $qrPayload = '',
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

        $sigX = $position['x']      ?? 20;
        $sigY = $position['y']      ?? 250;
        $sigW = $position['width']  ?? 60;
        $sigH = $position['height'] ?? 20;

        $pdf->Image($disk->path($imagePath), $sigX, $sigY, $sigW, $sigH);

        if ($qrPayload !== '') {
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

        $outName = config('signature.signed_docs_path')
            .'/'.pathinfo($pdfPath, PATHINFO_FILENAME)
            .'_signed_'.time().'.pdf';

        $disk->put($outName, $pdf->Output('', 'S'));

        return $outName;
    }
}