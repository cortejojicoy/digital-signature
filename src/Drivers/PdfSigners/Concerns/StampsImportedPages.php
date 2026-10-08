<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners\Concerns;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Re-creates every page of a source PDF through FPDI and draws the signature
 * stamp at each placement — the visual half of signing, shared by
 * `FpdiDriver` (which then signs with a local key) and `DeferredPdfSigner`
 * (which reserves the signature for the hub to fill), so the two cannot
 * disagree about where a signature lands.
 */
trait StampsImportedPages
{
    use DrawsSignatureStamp;

    /**
     * @param  Fpdi  $pdf  A fresh document, created in points ('pt').
     * @param  string  $sourceFsPath  Filesystem path of the PDF to import.
     * @param  string  $imageFsPath  Filesystem path of the signature image.
     * @param  array<int, array{page?:int,x?:float,y?:float,width?:float,height?:float}>  $stamps
     * @param  array<int, string>  $caption
     */
    protected function importAndStamp(
        Fpdi $pdf,
        string $sourceFsPath,
        string $imageFsPath,
        array $stamps,
        array $caption,
    ): Fpdi {
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // A signature is placed at explicit coordinates on a page that already
        // exists; there is never a reason for one to spill onto a new page.
        $pdf->SetAutoPageBreak(false);

        $count = $pdf->setSourceFile($sourceFsPath);

        for ($i = 1; $i <= $count; $i++) {
            $tpl = $pdf->importPage($i);
            $sz  = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($sz['orientation'], [$sz['width'], $sz['height']]);
            $pdf->useTemplate($tpl);

            foreach ($stamps as $stamp) {
                if ($i !== ($stamp['page'] ?? 1)) {
                    continue;
                }

                $sigX = $stamp['x']      ?? 20;
                $sigY = $stamp['y']      ?? 250;
                $sigW = $stamp['width']  ?? 60;
                $sigH = $stamp['height'] ?? 20;

                // Placements are PDF-native: y is the BOTTOM edge measured from
                // the BOTTOM of the page. TCPDF draws from the top-left corner,
                // so flip it against this page's own height.
                $topY = $sz['height'] - ($sigY + $sigH);

                // The name on every appearance, not just the first: a stamp
                // further down the document that does not say who made it is
                // an unattributed mark.
                $this->drawStamp(
                    $pdf,
                    $imageFsPath,
                    $sigX,
                    $topY,
                    $sigW,
                    $sigH,
                    $caption,
                );
            }
        }

        return $pdf;
    }
}
