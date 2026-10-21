<?php

namespace Kukux\DigitalSignature\Client;

use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Verification\DocumentSnapshot;

/**
 * Client mode: tell the hub about a routed document, so the hub's verify
 * page and Kukux Sign Agent can verify its PDFs without the app.
 *
 * What goes is the DocumentSnapshot the app's own verify page answers from:
 * each version's fingerprint and who signed which slot. Never the PDF.
 *
 * Triggered by ReportDocumentToHub (queued, on every signing event) and by
 * `signature:hub-sync-documents`, which also catches what no event covers,
 * like a session cancelled or expired.
 */
class HubDocumentReporter
{
    public function __construct(private readonly HubClient $hub)
    {
    }

    public static function enabled(): bool
    {
        return (bool) config('signature.hub.report_documents', true);
    }

    /**
     * @return bool  false when there was nothing to report yet (no frozen base)
     *
     * @throws HubException
     */
    public function report(SigningSession $session): bool
    {
        if ($session->base_document_hash === null || $session->uuid === null) {
            return false;
        }

        $this->hub->putDocument((string) $session->uuid, DocumentSnapshot::fromSession($session)->toArray());

        return true;
    }
}
