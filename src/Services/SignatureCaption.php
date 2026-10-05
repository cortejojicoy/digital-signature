<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Models\Signature;

/**
 * The text drawn beside a stamped signature, in the format COA Circular
 * No. 2021-006 (IV.C.13) gives as its example:
 *
 *     Digitally signed
 *     by Juan DelaCruz
 *     Date: 2020.05.21
 *     19:37:33 +08'00'
 *
 * Extracted so the placement UI and the PDF writer ask the same question of
 * the same object. If the preview guessed at the text independently, the
 * thing a signatory aligned against the form would not be the thing that
 * printed.
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

        $name = trim((string) $signature->user?->name);

        if ($name === '') {
            return [];
        }

        // The preview dates itself now and so does the stamp, each at the
        // moment it runs — so a signatory who leaves the drawer open over
        // lunch sees a slightly stale time. The alternative is freezing a
        // timestamp before the signature exists, which would print a lie.
        $moment = ($at ?? now())->copy();

        if ($zone = config('signature.caption.timezone')) {
            $moment->setTimezone((string) $zone);
        }

        // +08'00' rather than +08:00: the offset as PDF readers print it in
        // their own signature appearances, which is what the circular shows.
        $offset = str_replace(':', "'", $moment->format('P'))."'";

        return [
            (string) config('signature.caption.label', 'Digitally signed'),
            'by '.$name,
            'Date: '.$moment->format((string) config('signature.caption.date_format', 'Y.m.d')),
            $moment->format((string) config('signature.caption.time_format', 'H:i:s')).' '.$offset,
        ];
    }

    /**
     * The layout rules the browser needs to draw the stamp this package will
     * print: the text block's size and the gap beside the ink.
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
                'enabled'    => (bool) config('signature.caption.enabled', true),
                'fontSize'   => (float) config('signature.caption.font_pt', 7),
                'minFont'    => (float) config('signature.caption.min_font_pt', 4),
                'lineHeight' => (float) config('signature.caption.line_height', 1.15),
                'gap'        => (float) config('signature.caption.gap', 3),
            ],
        ];
    }
}
