<?php

namespace Kukux\DigitalSignature\Tests\Feature\Client;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner;
use Kukux\DigitalSignature\Drivers\PdfSigners\PreparedPdf;

/**
 * Stands in for Drivers\PdfSigners\DeferredPdfSigner: writes a "prepared"
 * file recording what it was asked to stamp, and an "injected" file holding
 * the CMS, so the client flow can be followed without real PDF bytes.
 */
class FakeDeferredPdfSigner implements DeferredPdfSigner
{
    /** @var list<array<string, mixed>> */
    public array $prepared = [];

    /** @var list<array{path: string, cms: string}> */
    public array $injected = [];

    public function prepare(
        string $pdfPath,
        string $imagePath,
        array $position,
        string $reason,
        array $caption = [],
        array $extraPositions = [],
        ?string $imageDisk = null,
    ): PreparedPdf {
        $disk = Storage::disk(config('signature.storage_disk'));
        $image = Storage::disk($imageDisk ?? config('signature.storage_disk'))->get($imagePath);
        $path = 'prepared/'.Str::uuid().'.pdf';
        $body = "%PDF-1.4\n% prepared from {$pdfPath} with ".hash('sha256', (string) $image)."\n".$disk->get($pdfPath);

        $disk->put($path, $body);

        $this->prepared[] = compact('pdfPath', 'imagePath', 'position', 'reason', 'caption', 'extraPositions', 'imageDisk', 'path');

        return new PreparedPdf($path, hash('sha256', $body), [0, 10, 20, 30], 32768);
    }

    public function inject(string $preparedPath, string $cmsDer): string
    {
        $disk = Storage::disk(config('signature.storage_disk'));
        $path = 'signed-docs/injected-'.Str::uuid().'.pdf';

        $disk->put($path, $disk->get($preparedPath)."\n% cms ".bin2hex($cmsDer));

        $this->injected[] = ['path' => $preparedPath, 'cms' => $cmsDer];

        return $path;
    }
}
