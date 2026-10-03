<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * One kind of document an app routes for signatures: everything about routing
 * it that is specific to that document, and nothing that isn't.
 *
 * The package owns the routing itself (DocumentRouter): the transaction, the
 * "already routed" short-circuit, the stock checks, opening the session and
 * the wording of the result. A definition supplies only what differs per
 * document: which template, how to find or create its record, any extra
 * checks, and wording overrides.
 *
 * Register by class-string, so `config:cache` keeps working and the container
 * builds it with whatever it depends on:
 *
 *   'documents' => ['dtr' => \App\Signatures\DtrDocument::class],
 *
 * Extend AbstractSignableDocument rather than implementing this from scratch.
 */
interface SignableDocument
{
    /** The registered PdfTemplate key this document renders with. */
    public function template(): string;

    /**
     * The record for this subject and context, or null if it was never filed.
     * Must not create anything: the document-of-record lookup calls this on
     * every view, and a view must not file a report.
     *
     * @param  array<string, mixed>  $context
     */
    public function locate(mixed $subject, array $context = []): ?Model;

    /**
     * Find or create the record to route. Called inside the routing
     * transaction, so whatever it writes is rolled back if routing refuses.
     *
     * @param  array<string, mixed>  $context
     */
    public function open(mixed $subject, array $context = []): Model;

    /**
     * Checks that need no record. Run first, outside the transaction.
     *
     * @return list<class-string<PreflightGuard>|PreflightGuard>
     */
    public function preflight(): array;

    /**
     * Checks on the opened record, run before the package's own.
     *
     * @return list<class-string<RoutingGuard>|RoutingGuard>
     */
    public function guards(): array;

    /**
     * Wording overrides, keyed by RoutingResult reason.
     *
     * @return array<string, array{title?: string, body?: string}>
     */
    public function messages(): array;
}
