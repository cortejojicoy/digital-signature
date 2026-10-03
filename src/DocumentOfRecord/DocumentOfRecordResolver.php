<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignableDocument;
use Kukux\DigitalSignature\Services\DocumentRegistry;

/**
 * Answers "which file is this document?" for pages that show one.
 *
 * Once a document has been routed, the answer is its document of record:
 * the stored PDF its signatories signed. Before that it is a draft, rendered
 * live by the page's own closure. One call covers both, so View and Download
 * can't disagree:
 *
 *   $pdf = app(DocumentOfRecordResolver::class)->resolve(
 *       'dtr', $employee, ['month' => $month],
 *       live: fn () => DtrPdf::render($employee, $month),
 *   );
 *
 *   $pdf->contents();            // bytes for an inline preview
 *   $pdf->banner();              // "2 of 4 signed · waiting on …", or null for a draft
 *   $pdf->download('AR.pdf');    // a StreamedResponse
 */
class DocumentOfRecordResolver
{
    public function __construct(protected DocumentRegistry $documents)
    {
    }

    /** The document of record for a record, or null if it was never routed. */
    public function forRecord(Model $record): ?DocumentOfRecord
    {
        return DocumentOfRecord::for($record);
    }

    /**
     * The document of record for what a page has in hand (a subject and a
     * context, say a person and a period). Never creates a record: a view
     * must not file anything.
     *
     * @param  array<string, mixed>  $context
     */
    public function locate(string|SignableDocument $document, mixed $subject, array $context = []): ?DocumentOfRecord
    {
        $record = $this->documents->resolve($document)->locate($subject, $context);

        return $record === null ? null : $this->forRecord($record);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure(): string  $live  renders the draft as PDF bytes
     */
    public function resolve(string|SignableDocument $document, mixed $subject, array $context, Closure $live): ResolvedDocument
    {
        return new ResolvedDocument($this->locate($document, $subject, $context), $live);
    }

    public function history(Model $record): DocumentHistory
    {
        return DocumentHistory::for($record);
    }
}
