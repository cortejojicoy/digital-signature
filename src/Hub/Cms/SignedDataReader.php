<?php

namespace Kukux\DigitalSignature\Hub\Cms;

use Kukux\DigitalSignature\Support\Der\Der;
use Kukux\DigitalSignature\Support\Der\DerException;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Support\Der\Oid;
use Kukux\DigitalSignature\Support\Der\X509;

/**
 * Reads back a detached CMS SignedData with a single SignerInfo — the shape
 * CmsSigner produces — far enough to check it independently of OpenSSL's CMS
 * code: which digest it claims to sign, which certificate signed, and whether
 * the signature over the signed attributes holds.
 *
 * Used before a CMS is injected into a PDF (a CMS for the wrong /ByteRange is
 * refused rather than producing a broken document) and by the tests.
 */
final class SignedDataReader
{
    /**
     * @param  list<string>  $certificates  DER.
     * @param  array<string, list<Element>>  $signedAttributes  oid => values.
     * @param  array<string, list<Element>>  $unsignedAttributes  oid => values.
     */
    private function __construct(
        public readonly array $certificates,
        public readonly string $digestAlgorithm,
        public readonly string $signerIssuer,
        public readonly string $signerSerial,
        /** DER SET OF the signed attributes: the exact bytes that were signed. */
        public readonly string $signedAttributesDer,
        public readonly array $signedAttributes,
        public readonly string $signatureAlgorithm,
        public readonly string $signature,
        public readonly array $unsignedAttributes,
    ) {}

    public static function parse(string $cmsDer): self
    {
        try {
            $info = Element::parse($cmsDer)->expect(Der::SEQUENCE, 'a ContentInfo');

            if ($info->child(0)->oid() !== Oid::SIGNED_DATA) {
                throw new CmsException('The CMS is not a SignedData.');
            }

            $signedData = $info->child(1)->child(0)->expect(Der::SEQUENCE, 'a SignedData');
            $fields     = $signedData->children();

            $encap = $fields[2]->expect(Der::SEQUENCE, 'the encapContentInfo');
            if ($encap->contextChild(0) !== null) {
                throw new CmsException('The CMS carries its content; a PDF signature must be detached.');
            }

            $certificates = [];
            $signerInfos  = null;

            foreach (array_slice($fields, 3) as $field) {
                if ($field->isContext(0)) {
                    $certificates = array_map(fn (Element $c): string => $c->encoded, $field->children());
                } elseif ($field->is(Der::SET)) {
                    $signerInfos = $field;
                }
            }

            $signers = $signerInfos?->children() ?? [];
            if (count($signers) !== 1) {
                throw new CmsException('Expected exactly one SignerInfo, found '.count($signers).'.');
            }

            $signer = $signers[0]->children();
            $sid    = $signer[1]->expect(Der::SEQUENCE, 'an IssuerAndSerialNumber');

            $at          = 3;
            $signedAttrs = null;

            if ($signer[$at]->isContext(0)) {
                $signedAttrs = $signer[$at];
                $at++;
            }

            if ($signedAttrs === null) {
                throw new CmsException('The SignerInfo has no signed attributes.');
            }

            $unsigned = null;
            if (isset($signer[$at + 2]) && $signer[$at + 2]->isContext(1)) {
                $unsigned = $signer[$at + 2];
            }

            return new self(
                certificates:        $certificates,
                digestAlgorithm:     $signer[2]->child(0)->oid(),
                signerIssuer:        $sid->child(0)->encoded,
                signerSerial:        $sid->child(1)->encoded,
                // RFC 5652 §5.4: the [0] IMPLICIT tag is replaced by the SET
                // OF tag; every other byte is hashed as it was transmitted.
                signedAttributesDer: chr(Der::SET).substr($signedAttrs->encoded, 1),
                signedAttributes:    self::attributes($signedAttrs),
                signatureAlgorithm:  $signer[$at]->child(0)->oid(),
                signature:           $signer[$at + 1]->expect(Der::OCTET_STRING, 'the signature')->content,
                unsignedAttributes:  $unsigned !== null ? self::attributes($unsigned) : [],
            );
        } catch (DerException $e) {
            throw new CmsException('Malformed CMS: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The messageDigest attribute: the hash the signer signed.
     */
    public function messageDigest(): ?string
    {
        return $this->signedAttributes[Oid::MESSAGE_DIGEST][0]->content ?? null;
    }

    public function timestampToken(): ?string
    {
        return $this->unsignedAttributes[Oid::SIGNATURE_TIMESTAMP_TOKEN][0]->encoded ?? null;
    }

    /**
     * The embedded certificate the SignerInfo points at, DER.
     */
    public function signerCertificate(): ?string
    {
        foreach ($this->certificates as $der) {
            try {
                $cert = X509::fromDer($der);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($cert->issuer === $this->signerIssuer && $cert->serial === $this->signerSerial) {
                return $der;
            }
        }

        return null;
    }

    /**
     * Does the signature over the signed attributes verify with the signer's
     * embedded certificate? Says nothing about whether that certificate is
     * trusted — that is chain validation, done by whoever relies on it.
     */
    public function verifiesWithSignerCertificate(): bool
    {
        $der = $this->signerCertificate();

        if ($der === null || $this->digestAlgorithm !== Oid::SHA256) {
            return false;
        }

        $key = openssl_pkey_get_public(X509::toPem($der));

        return $key !== false
            && openssl_verify($this->signedAttributesDer, $this->signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @return array<string, list<Element>>
     */
    private static function attributes(Element $set): array
    {
        $out = [];

        foreach ($set->children() as $attribute) {
            $out[$attribute->child(0)->oid()] = $attribute->child(1)->children();
        }

        return $out;
    }
}
