<?php

namespace Kukux\DigitalSignature\Hub\Documents;

use Kukux\DigitalSignature\Hub\Signing\SignerCertificates;
use Kukux\DigitalSignature\Verification\CertificateStatus;
use Kukux\DigitalSignature\Verification\DocumentLookup;
use Kukux\DigitalSignature\Verification\DocumentSnapshot;

/**
 * DocumentLookup at the hub: answers from what apps reported (HubDocuments)
 * instead of from local signing sessions, which the hub doesn't have.
 *
 * Same verdicts, same disclosure, same endpoint (POST /signature/verify/lookup),
 * so the hub's verify page and Kukux Sign Agent verify a PDF from any app the
 * way that app's own page would. Two things differ:
 *
 *   - the issuer named in the answer is the app that reported the document,
 *     not the hub: the hub is vouching that the app said so;
 *   - certificate status is the hub's own, since it issued the certificates.
 *
 * Bound over DocumentLookup by HubApiServiceProvider.
 */
class HubDocumentLookup extends DocumentLookup
{
    public function __construct(
        CertificateStatus $certificates,
        private readonly HubDocuments $documents,
        private readonly SignerCertificates $signerCertificates,
    ) {
        parent::__construct($certificates);
    }

    protected function locate(string $hash): ?DocumentSnapshot
    {
        $document = $this->documents->findByFingerprint($hash);

        return $document?->snapshot()->issuedBy($document->app?->name);
    }

    protected function certificateOf(array $slot): string
    {
        if ($slot['revoked']) {
            return 'revoked';
        }

        $fingerprint = (string) $slot['certificate_fingerprint'];

        if ($fingerprint === '') {
            return 'unknown';
        }

        $certificate = $this->signerCertificates->find($fingerprint);

        return match (true) {
            $certificate === null     => 'unknown',
            $certificate->isRevoked() => 'revoked',
            default                   => 'valid',
        };
    }
}
