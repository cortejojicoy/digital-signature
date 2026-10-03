<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\DocumentOfRecordGate;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentVersion;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Streams a routed document's versions: the current one, or any single one.
 *
 *   GET /signature/documents/{session}                    the session's latest version
 *   GET /signature/documents/{session}/versions/{version}  version N (0 = the base render)
 *
 * Addressed by session uuid, which is not guessable and names exactly one run
 * of signing. Who may read what is the DocumentOfRecordGate's call, bound in
 * the container. Every serve re-checks the file against the hash recorded
 * when it was signed, and says so in `X-Document-Integrity`.
 *
 * `web` only, not `auth`, for the reason given on the package's other
 * document routes: the bare `auth` middleware uses the app's default guard,
 * which is often not the panel's. The gate is the check that matters.
 */
class DocumentOfRecordController extends Controller
{
    public function __construct(protected DocumentOfRecordGate $gate)
    {
    }

    public function show(Request $request, string $session): Response
    {
        [$signingSession, $record] = $this->sessionAndRecord($session);

        abort_unless($this->gate->canView($request->user(), $record), 403, 'You may not view this document.');

        $version = DocumentHistory::versionsOf($signingSession)->last();

        return $this->stream($version, $request->boolean('download'), $record);
    }

    public function version(Request $request, string $session, int $version): Response
    {
        [$signingSession, $record] = $this->sessionAndRecord($session);

        $found = DocumentHistory::versionsOf($signingSession)
            ->first(fn (DocumentVersion $v): bool => $v->number === $version);

        abort_unless($found !== null, 404, 'This document has no such version.');

        abort_unless(
            $this->gate->canViewVersion($request->user(), $record, $found),
            403,
            'You may not view this document.',
        );

        return $this->stream($found, $request->boolean('download'), $record);
    }

    /** @return array{0: SigningSession, 1: \Illuminate\Database\Eloquent\Model} */
    protected function sessionAndRecord(string $uuid): array
    {
        $session = SigningSession::query()->where('uuid', $uuid)->first();

        abort_unless($session !== null, 404, 'No such document.');

        $record = $session->signable;

        abort_unless($record !== null, 404, 'The record behind this document no longer exists.');

        return [$session, $record];
    }

    protected function stream(DocumentVersion $version, bool $download, $record): Response
    {
        abort_unless($version->exists(), 404, 'This version is missing from storage.');

        $intact = $version->verify();
        $bytes = $version->serve();

        $title = method_exists($record, 'getSignableTitle') ? $record->getSignableTitle() : 'document';
        $filename = Str::slug($title).'-v'.$version->number.'.pdf';

        return response($bytes, 200, [
            'Content-Type'         => 'application/pdf',
            'Content-Disposition'  => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            // The URL names a document, the gate decides who reads it: never
            // let a shared cache answer for the gate.
            'Cache-Control'        => 'private, no-store',
            'X-Document-Version'   => (string) $version->number,
            'X-Document-Integrity' => $intact ? 'verified' : 'mismatch',
        ]);
    }
}
