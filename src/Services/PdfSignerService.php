<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Models\Signature;

class PdfSignerService
{
    public function __construct(protected PdfSignerDriver $driver) {}

    public function sign(Signature $signature, array $certData): string
    {
        $position = $signature->position
            ? $signature->position->only(['page', 'x', 'y', 'width', 'height'])
            : [];

        return $this->driver->sign(
            pdfPath:   $signature->signable->getSignablePdfPath(),
            imagePath: $signature->image_path,
            position:  $position,
            certData:  $certData,
            reason:    'Signed via '.config('app.name'),
        );
    }
}
