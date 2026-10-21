<?php

namespace Kukux\DigitalSignature\Hub\Documents;

use Illuminate\Support\Facades\DB;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubDocument;
use Kukux\DigitalSignature\Models\HubDocumentVersion;
use Kukux\DigitalSignature\Verification\DocumentSnapshot;

/**
 * The hub's register of documents apps routed, kept so the hub can verify a
 * PDF from any app without holding the PDF.
 *
 * An app reports the whole document every time something changes (a
 * signature, a decline, completion), so recording is a replace, not a merge:
 * the latest report wins, and a retried report is harmless.
 *
 * What this trusts: the app. A report says "these fingerprints are versions
 * of a document I issued, signed by these people", and the hub repeats that
 * when asked, naming the app as the issuer. It can't do better than that
 * without the document, and an app that would lie here could equally lie in
 * the document itself.
 */
class HubDocuments
{
    public function record(HubApp $app, string $uuid, DocumentSnapshot $snapshot): HubDocument
    {
        return DB::transaction(function () use ($app, $uuid, $snapshot): HubDocument {
            $document = HubDocument::query()->updateOrCreate(
                ['app_id' => $app->id, 'uuid' => strtolower($uuid)],
                [
                    'title'       => $snapshot->title,
                    'status'      => $snapshot->status,
                    'report'      => $snapshot->toArray(),
                    'reported_at' => now(),
                ],
            );

            $document->versions()->delete();

            foreach ($snapshot->versions as $version) {
                HubDocumentVersion::create([
                    'document_id' => $document->id,
                    'number'      => $version['number'],
                    'sha256'      => $version['sha256'],
                ]);
            }

            return $document;
        });
    }

    /**
     * The document a fingerprint is a version of.
     *
     * If two documents claim the same bytes, the one reported first wins.
     * The app that issued a document reports it the moment it is routed, so
     * it is always first; a later claim to the same fingerprint is another
     * app trying to vouch for a file it didn't issue, and must not replace
     * the issuer's answer. (One app re-reporting its own document updates
     * that same row, so this never stops a legitimate update.)
     */
    public function findByFingerprint(string $sha256): ?HubDocument
    {
        return HubDocument::query()
            ->whereIn('id', HubDocumentVersion::query()->select('document_id')->where('sha256', strtolower($sha256)))
            ->with('app')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
