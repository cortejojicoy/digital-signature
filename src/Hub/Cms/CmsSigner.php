<?php

namespace Kukux\DigitalSignature\Hub\Cms;

use DateTimeInterface;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Support\Der\Der;
use Kukux\DigitalSignature\Support\Der\Oid;
use Kukux\DigitalSignature\Support\Der\X509;
use OpenSSLAsymmetricKey;

/**
 * Detached CMS SignedData over a digest computed somewhere else.
 *
 * openssl_pkcs7_sign() and openssl_cms_sign() both want the CONTENT and hash
 * it themselves. In hash-only signing the hub never sees the document — an
 * app sends the SHA-256 of a PDF's /ByteRange — so the structure is built by
 * hand and only the final RSA/ECDSA operation goes through OpenSSL:
 *
 *   ContentInfo { signedData, SignedData {
 *     version 1, digestAlgorithms {sha256},
 *     encapContentInfo { id-data }            ← detached: no eContent
 *     certificates [0] { signer, chain… }
 *     signerInfos { SignerInfo {
 *       version 1, sid IssuerAndSerialNumber, sha256,
 *       signedAttrs [0] { contentType, signingTime, messageDigest,
 *                         signingCertificateV2 }
 *       rsaEncryption | ecdsa-with-SHA256, signature,
 *       unsignedAttrs [1] { signatureTimeStampToken }?   ← when a TSA is set
 *   } } } }
 *
 * The signature is over DER(SET OF signedAttrs) — RFC 5652 §5.4 — which is
 * why the messageDigest attribute is all the document a signer needs.
 * signingCertificateV2 binds the certificate itself into what was signed
 * (CAdES-BES / PAdES require it), so a verifier cannot be handed a different
 * certificate with the same key.
 */
class CmsSigner implements DigestSigner
{
    public function __construct(
        protected ?TimestampAuthority $tsa = null,
    ) {}

    public function signDigest(
        string $digestHex,
        string $certificatePem,
        mixed $privateKey,
        array $chainPem = [],
        ?string $tsaUrl = null,
    ): string {
        $digest = $this->digestBytes($digestHex);
        $cert   = $this->certificate($certificatePem);
        $key    = $this->privateKey($privateKey);

        if (! openssl_x509_check_private_key(X509::toPem($cert->der), $key)) {
            throw new CmsException('The private key does not belong to the signing certificate.');
        }

        $signedAttrs = Der::set(...$this->signedAttributes($digest, $cert, now()));

        if (! openssl_sign($signedAttrs, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new CmsException('OpenSSL could not sign: '.$this->opensslErrors());
        }

        $unsignedAttrs = null;

        if ($tsaUrl !== null && $tsaUrl !== '') {
            // RFC 3161 appendix A: the token's imprint is the hash of the
            // SignerInfo's signature value — it proves the signature existed
            // at genTime, not the document.
            $token = ($this->tsa ?? app(TimestampAuthority::class))
                ->timestamp($tsaUrl, hash('sha256', $signature, true));

            $unsignedAttrs = Der::implicit(1, Der::set(
                Der::sequence(Der::oid(Oid::SIGNATURE_TIMESTAMP_TOKEN), Der::set(Der::raw($token))),
            ));
        }

        $signerInfo = Der::sequence(
            Der::integer(1),
            Der::sequence(Der::raw($cert->issuer), Der::raw($cert->serial)),
            $this->sha256Algorithm(),
            // [0] IMPLICIT: the same bytes that were signed, re-tagged. The
            // verifier turns the tag back into SET to recompute the hash.
            Der::implicit(0, $signedAttrs),
            $this->signatureAlgorithm($key),
            Der::octetString($signature),
            ...($unsignedAttrs !== null ? [$unsignedAttrs] : []),
        );

        $certificates = [$cert->der];
        foreach ($chainPem as $pem) {
            foreach ($this->pemCertificates((string) $pem) as $der) {
                $certificates[] = $der;
            }
        }

        $signedData = Der::sequence(
            Der::integer(1),
            Der::set($this->sha256Algorithm()),
            Der::sequence(Der::oid(Oid::DATA)),
            Der::implicit(0, Der::set(...array_values(array_unique($certificates)))),
            Der::set($signerInfo),
        );

        return Der::sequence(
            Der::oid(Oid::SIGNED_DATA),
            Der::explicit(0, $signedData),
        );
    }

    // -------------------------------------------------------------------------

    /**
     * The four signed attributes, each a complete Attribute encoding.
     *
     * @return list<string>
     */
    protected function signedAttributes(string $digest, X509 $cert, DateTimeInterface $signingTime): array
    {
        // ESSCertIDv2 { hashAlgorithm DEFAULT sha256 (so omitted — DER drops
        // defaults), certHash, issuerSerial { GeneralNames { [4] Name }, serial } }
        $essCertId = Der::sequence(
            Der::octetString(hash('sha256', $cert->der, true)),
            Der::sequence(
                Der::sequence(Der::explicit(4, Der::raw($cert->issuer))),
                Der::raw($cert->serial),
            ),
        );

        return [
            $this->attribute(Oid::CONTENT_TYPE, Der::oid(Oid::DATA)),
            $this->attribute(Oid::SIGNING_TIME, Der::time($signingTime)),
            $this->attribute(Oid::MESSAGE_DIGEST, Der::octetString($digest)),
            $this->attribute(Oid::SIGNING_CERTIFICATE_V2, Der::sequence(Der::sequence($essCertId))),
        ];
    }

    protected function attribute(string $oid, string $value): string
    {
        return Der::sequence(Der::oid($oid), Der::set($value));
    }

    /**
     * sha256 with parameters absent, as RFC 5754 §2 says to produce them.
     */
    protected function sha256Algorithm(): string
    {
        return Der::sequence(Der::oid(Oid::SHA256));
    }

    /**
     * rsaEncryption (NULL parameters, RFC 3370 §3.2) for RSA keys — what
     * openssl and Acrobat themselves write — and ecdsa-with-SHA256 (no
     * parameters, RFC 5758) for EC keys.
     */
    protected function signatureAlgorithm(OpenSSLAsymmetricKey $key): string
    {
        $type = openssl_pkey_get_details($key)['type'] ?? null;

        return match ($type) {
            OPENSSL_KEYTYPE_RSA => Der::sequence(Der::oid(Oid::RSA_ENCRYPTION), Der::null()),
            OPENSSL_KEYTYPE_EC  => Der::sequence(Der::oid(Oid::ECDSA_WITH_SHA256)),
            default             => throw new CmsException('Only RSA and EC signing keys are supported.'),
        };
    }

    private function digestBytes(string $digestHex): string
    {
        if (preg_match('/^[0-9a-fA-F]{64}$/', $digestHex) !== 1) {
            throw new CmsException('The digest must be a SHA-256 hash: exactly 64 hexadecimal characters.');
        }

        return (string) hex2bin($digestHex);
    }

    private function certificate(string $pem): X509
    {
        $blocks = $this->pemCertificates($pem);

        if ($blocks === []) {
            throw new CmsException('The signing certificate must be a PEM "BEGIN CERTIFICATE" block.');
        }

        return X509::fromDer($blocks[0]);
    }

    /**
     * @return list<string>
     */
    private function pemCertificates(string $pem): array
    {
        try {
            $blocks = X509::pemBlocks($pem);

            foreach ($blocks as $der) {
                X509::fromDer($der);
            }
        } catch (\InvalidArgumentException $e) {
            throw new CmsException($e->getMessage(), 0, $e);
        }

        return $blocks;
    }

    private function privateKey(mixed $privateKey): OpenSSLAsymmetricKey
    {
        if ($privateKey instanceof OpenSSLAsymmetricKey) {
            $details = openssl_pkey_get_details($privateKey);

            // A public key is also an OpenSSLAsymmetricKey; signing with it
            // fails deep inside OpenSSL with an unhelpful message.
            if ($details === false || ! $this->hasPrivatePart($details)) {
                throw new CmsException('The key given is not a private key.');
            }

            return $privateKey;
        }

        if (! is_string($privateKey) || trim($privateKey) === '') {
            throw new CmsException('The private key must be a PEM string or an OpenSSLAsymmetricKey.');
        }

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new CmsException('The private key could not be read (an encrypted key must be decrypted first): '.$this->opensslErrors());
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function hasPrivatePart(array $details): bool
    {
        return isset($details['rsa']['d']) || isset($details['ec']['d']);
    }

    private function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'no detail from OpenSSL' : implode('; ', $errors);
    }
}
