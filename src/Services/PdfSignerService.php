<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Models\Signature;

class PdfSignerService
{
    public function __construct(protected PdfSignerDriver $driver) {}

    /**
     * @param  string|null  $sourcePdfPath  Disk-relative path of the PDF to sign.
     *   Multi-signatory sessions pass the session's running document so each
     *   signature lands on top of the previous one's output; without it the
     *   signable is re-rendered and every earlier stamp is lost.
     */
    public function sign(Signature $signature, array $certData, ?string $sourcePdfPath = null): string
    {
        $position = $signature->position
            ? $signature->position->only(['page', 'x', 'y', 'width', 'height'])
            : [];

        return $this->driver->sign(
            pdfPath:   $sourcePdfPath ?? $signature->signable->getSignablePdfPath(),
            imagePath: $signature->image_path,
            position:  $position,
            certData:  $certData,
            reason:    'Signed via '.config('app.name'),
            qrPayload: $this->buildQrPayload($signature),
        );
    }

    private function buildQrPayload(Signature $signature): string
    {
        $signer = $signature->user;
        $appUrl = rtrim((string) config('app.url'), '/');

        $lines = [
            'App: '.config('app.name'),
            'Signer: '.($signer?->name ?? 'Unknown').' <'.($signer?->email ?? '').'>',
            'Signature: '.$signature->uuid,
            'Signed: '.now()->toIso8601String(),
        ];

        if ($appUrl !== '') {
            $lines[] = 'Verify: '.$appUrl.'/signatures/'.$signature->uuid;
        }

        return implode("\n", $lines);
    }
}
