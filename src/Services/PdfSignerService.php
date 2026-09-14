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
     * Everything binding this signature to its signer is already in the
     * document — HMAC chunks in the PNG, a PKCS#7 block, the QR — and every
     * bit of it is invisible on a printed page. These two or three lines are
     * what somebody holding that page can check against: a name, a moment, and
     * a reference to quote.
     *
     * Which lines appear is config, because what a form has room for is a
     * property of the form. The driver drops lines that do not fit rather than
     * overflowing the signatory's placement.
     *
     * @return array<int, string>
     */
    private function buildCaption(Signature $signature): array
    {
        if (! config('signature.caption.enabled', true)) {
            return [];
        }

        $signer = $signature->user;

        // now(), not signed_at: the column is written once the signed PDF
        // exists, which is after this runs. The QR payload has always dated
        // itself the same way, and the two must not disagree on the same page.
        $moment = now();

        $available = [
            'signer'    => $signer?->name,
            'email'     => $signer?->email,
            'signed_at' => 'Signed '.$moment->format(
                (string) config('signature.caption.date_format', 'j M Y H:i'),
            ),
            'reference' => 'Ref '.substr((string) $signature->uuid, 0, 8),
        ];

        $wanted = (array) config('signature.caption.fields', ['signer', 'signed_at', 'reference']);

        return array_values(array_filter(
            array_map(fn (string $field): string => (string) ($available[$field] ?? ''), $wanted),
            fn (string $line): bool => trim($line) !== '',
        ));
    }

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
