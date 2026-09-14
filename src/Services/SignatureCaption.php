<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Models\Signature;

/**
 * The lines of readable provenance that go under a stamped signature.
 *
 * Extracted so the placement UI and the PDF writer ask the same question of
 * the same object. The signatory is positioning a box that will end up holding
 * ink, a QR and this text; if the preview guessed at the text independently,
 * the thing they aligned against the form would not be the thing that printed.
 */
class SignatureCaption
{
    /**
     * @return array<int, string>
     */
    public function linesFor(Signature $signature, ?Carbon $at = null): array
    {
        if (! config('signature.caption.enabled', true)) {
            return [];
        }

        $signer = $signature->user;

        // The preview dates itself now and so does the stamp, each at the
        // moment it runs — so a signatory who leaves the drawer open over
        // lunch sees a slightly stale time. The alternative is freezing a
        // timestamp before the signature exists, which would print a lie.
        $moment = $at ?? now();

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

    /**
     * The layout rules the browser needs to draw the same bands this package
     * will stamp: how much height the caption may claim, and whether a QR fits.
     *
     * Sent to the client rather than a finished layout, because the box is
     * being dragged and resized — the rules are stable, the box is not.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'caption' => [
                'enabled'      => (bool) config('signature.caption.enabled', true),
                'minBoxHeight' => (float) config('signature.caption.min_box_height', 28),
                'heightRatio'  => (float) config('signature.caption.height_ratio', 0.38),
                'maxFont'      => (float) config('signature.caption.max_font_pt', 6),
                'minFont'      => (float) config('signature.caption.min_font_pt', 4),
                'lineHeight'   => (float) config('signature.caption.line_height', 1.06),
                'align'        => (string) config('signature.caption.align', 'C'),
            ],
            'qr' => [
                'enabled' => (bool) config('signature.qr.enabled', true)
                    && (bool) config('signature.verify.enabled', true),
                'minSize' => (float) config('signature.qr.min_size', 26),
                'maxSize' => (float) config('signature.qr.max_size', 48),
                'gap'     => (float) config('signature.qr.gap', 2),
            ],
        ];
    }
}
