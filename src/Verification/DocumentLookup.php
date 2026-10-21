<?php

namespace Kukux\DigitalSignature\Verification;

use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * "Is this file one we issued?", answered from a fingerprint alone.
 *
 * Every routed document already leaves a fingerprint per version: the frozen
 * base (`digital_signing_sessions.base_document_hash`, version 0) and each
 * signature's output (`digital_signatures.signed_document_hash`, versions
 * 1..n). So this stores nothing new; it looks a hash up and lets
 * DocumentSnapshot say what that version is.
 *
 * Two fingerprints come in:
 *
 *   sha256                  the whole file. A match is an exact copy of a
 *                           version, and the verdict says which one and how
 *                           far signing had got.
 *   signed_revision_sha256  the file cut at the end of its last signature
 *                           (resources/js/verify/pdfSignature.js). Different
 *                           from sha256 only when bytes were added after
 *                           signing. A match there means "this is that
 *                           version, saved again since".
 *
 * **What it discloses.** Only what the document already shows its holder:
 * the template's name, each slot's label, and for the slots this copy carries
 * who signed and when. Not the record behind it, not who is still to sign,
 * not emails. A SHA-256 can't be guessed, so nobody without the file can ask
 * about it, and a miss looks the same whether or not the hash was ever seen.
 *
 * At the hub, HubDocumentLookup answers instead, from what apps reported.
 */
class DocumentLookup
{
    public function __construct(private readonly CertificateStatus $certificates)
    {
    }

    public function find(string $sha256, ?string $signedRevisionSha256 = null): DocumentVerification
    {
        $sha256 = strtolower($sha256);
        $signedRevisionSha256 = $signedRevisionSha256 === null ? null : strtolower($signedRevisionSha256);

        if (($found = $this->locate($sha256)) !== null) {
            return $this->answer($found, $sha256, changedAfterSigning: false);
        }

        if ($signedRevisionSha256 !== null && $signedRevisionSha256 !== $sha256
            && ($found = $this->locate($signedRevisionSha256)) !== null) {
            return $this->answer($found, $signedRevisionSha256, changedAfterSigning: true);
        }

        return DocumentVerification::notIssued();
    }

    /** The document a fingerprint belongs to, or null. */
    protected function locate(string $hash): ?DocumentSnapshot
    {
        $session = SigningSession::query()
            ->whereIn('id', Signature::query()
                ->select('signing_session_id')
                ->where('signed_document_hash', $hash)
                ->where('status', 'signed')
                ->whereNotNull('signing_session_id'))
            ->orWhere('base_document_hash', $hash)
            ->orderByDesc('id')
            ->first();

        return $session === null ? null : DocumentSnapshot::fromSession($session);
    }

    protected function answer(DocumentSnapshot $document, string $hash, bool $changedAfterSigning): DocumentVerification
    {
        $number = $document->versionOf($hash);

        if ($number === null) {
            return DocumentVerification::notIssued();
        }

        return $document->verify($number, $changedAfterSigning, fn (array $slot): string => $this->certificateOf($slot));
    }

    /** @param  array{certificate_fingerprint: ?string, revoked: bool}  $slot */
    protected function certificateOf(array $slot): string
    {
        return $this->certificates->forFingerprint($slot['certificate_fingerprint'], $slot['revoked']);
    }
}
