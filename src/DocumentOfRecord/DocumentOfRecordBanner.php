<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Illuminate\Support\HtmlString;

/**
 * The one line over a routed document saying what it is and where it stands:
 * "Signed by all 4 · 3 Oct 2026", "2 of 4 signed · waiting on Dr Reyes",
 * "Routing withdrawn", and a warning when the file no longer matches what was
 * signed.
 *
 *   {!! DocumentOfRecordBanner::render($report->documentOfRecord()) !!}
 *   <x-signature::document-of-record-banner :document="$report->documentOfRecord()" />
 */
final class DocumentOfRecordBanner
{
    public static function render(?DocumentOfRecord $document): HtmlString
    {
        if ($document === null) {
            return new HtmlString('');
        }

        return new HtmlString(view('signature::components.document-of-record-banner', [
            'document' => $document,
        ])->render());
    }
}
