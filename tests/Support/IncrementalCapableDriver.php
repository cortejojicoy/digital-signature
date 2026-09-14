<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Kukux\DigitalSignature\Contracts\SupportsIncrementalSigning;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;

/**
 * Stand-in for a driver that CAN write ISO 32000 incremental updates (e.g. a
 * SetaPDF-Signer-backed one). Used to prove the session accepts incremental
 * mode when — and only when — the driver declares the capability.
 */
class IncrementalCapableDriver extends FpdiDriver implements SupportsIncrementalSigning
{
    public function appendSignature(
        string $signedPdfPath,
        string $imagePath,
        array $position,
        array $certData,
        string $reason,
        string $qrPayload = '',
    ): string {
        return $signedPdfPath;
    }
}
