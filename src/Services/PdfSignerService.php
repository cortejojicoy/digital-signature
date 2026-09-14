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
        // Every place this signature is drawn, not just the first. Falls back
        // to the singular relation so a Signature loaded the old way — or one
        // built by a host's own code — still stamps.
        $stamps = $signature->exists ? $signature->positions()->get() : collect();

        if ($stamps->isEmpty() && $signature->position) {
            $stamps = collect([$signature->position]);
        }

        $all = $stamps
            ->map(fn ($p): array => $p->only(['page', 'x', 'y', 'width', 'height']))
            ->values();

        $position = $all->first() ?? [];

        return $this->driver->sign(
            pdfPath:   $sourcePdfPath ?? $signature->signable->getSignablePdfPath(),
            imagePath: $signature->image_path,
            position:  $position,
            certData:  $certData,
            reason:    'Signed via '.config('app.name'),
            qrPayload: $this->buildQrPayload($signature),
            caption:   $this->buildCaption($signature),
            extraPositions: $all->slice(1)->values()->all(),
        );
    }

    /**
     * The provenance a person can actually read.
     *
     * Built by SignatureCaption, shared with the placement UI so the box a
     * signatory aligns against the form holds the text that actually prints.
     *
     * @return array<int, string>
     */
    private function buildCaption(Signature $signature): array
    {
        return app(SignatureCaption::class)->linesFor($signature);
    }

    /**
     * What the QR resolves to when somebody scans the printed page.
     *
     * A URL, not a block of text. The previous payload was four labelled lines
     * including a `Verify:` address that pointed at a route this package never
     * registered — so scanning it produced a wall of text and a dead link. A
     * bare URL opens the verification page, which is the only form of "scan
     * this to check the signature" that actually checks anything.
     */
    private function buildQrPayload(Signature $signature): string
    {
        // Opt-out: the QR is drawn immediately to the right of the signature,
        // which needs roughly another signature's width of clear space. Forms
        // that put signatures side by side (a three-column "Prepared / Attested
        // / Noted" row, say) have nowhere to put it, and it lands on top of the
        // neighbouring block.
        if (! config('signature.qr.enabled', true)) {
            return '';
        }

        try {
            return route('signature.verify', ['uuid' => $signature->uuid]);
        } catch (\Throwable) {
            // Route not registered — a host that disabled the package's routes,
            // or a unit test booting the service alone. No QR is better than
            // one that leads nowhere.
            return '';
        }
    }
}
