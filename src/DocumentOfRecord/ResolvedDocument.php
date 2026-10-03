<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Closure;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PDF to show for a document right now: its document of record once it
 * has been routed, a live render while it is still a draft.
 *
 * Returned by DocumentOfRecordResolver::resolve(), so View and Download on a
 * page that generates documents share one answer to "which file is this?".
 */
final class ResolvedDocument
{
    private ?string $bytes = null;

    /** @param  Closure(): string  $live  renders the draft; only called when there's no record */
    public function __construct(
        public readonly ?DocumentOfRecord $ofRecord,
        private readonly Closure $live,
    ) {
    }

    /** Is this the stored, routed document (rather than a live draft)? */
    public function isOfRecord(): bool
    {
        return $this->ofRecord !== null;
    }

    public function contents(): string
    {
        return $this->bytes ??= $this->ofRecord !== null
            ? $this->ofRecord->contents()
            : (string) ($this->live)();
    }

    /** The banner for a routed document; null for a draft. */
    public function banner(): ?HtmlString
    {
        return $this->ofRecord === null ? null : DocumentOfRecordBanner::render($this->ofRecord);
    }

    public function download(string $filename): StreamedResponse
    {
        $name = $this->ofRecord?->downloadName($filename) ?? $filename;

        return response()->streamDownload(function (): void {
            echo $this->contents();
        }, $name, ['Content-Type' => 'application/pdf']);
    }
}
