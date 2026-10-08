<?php

namespace Kukux\DigitalSignature\Contracts;

/**
 * Turns a digest into a detached CMS (PKCS#7) SignedData, the bytes a PDF
 * reader verifies. The hub uses it to sign the /ByteRange digest an app sends
 * (hash-only signing, docs/hub/index.md "Deferred signing").
 *
 * Implemented by Hub\Cms\CmsSigner.
 */
interface DigestSigner
{
    /**
     * @param  string  $digestHex  SHA-256 of the signed content, 64 hex chars.
     * @param  string  $certificatePem  The signer's certificate.
     * @param  \OpenSSLAsymmetricKey|string  $privateKey  PEM or key resource.
     * @param  array<int, string>  $chainPem  Intermediate/root certificates to embed.
     * @param  string|null  $tsaUrl  RFC 3161 timestamp authority, or null.
     * @return string  DER-encoded ContentInfo(SignedData), detached.
     */
    public function signDigest(
        string $digestHex,
        string $certificatePem,
        mixed $privateKey,
        array $chainPem = [],
        ?string $tsaUrl = null,
    ): string;
}
