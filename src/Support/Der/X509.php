<?php

namespace Kukux\DigitalSignature\Support\Der;

use InvalidArgumentException;

/**
 * The few X.509 fields CMS needs, read straight out of the certificate's DER.
 *
 * openssl_x509_parse() would give the issuer as an array and the serial as a
 * decimal string — both re-encodings. IssuerAndSerialNumber has to carry the
 * issuer Name byte-for-byte as it appears in the certificate (a verifier
 * compares encodings), so it is lifted from the TBSCertificate instead.
 */
final class X509
{
    private function __construct(
        /** The whole certificate, DER. */
        public readonly string $der,
        /** The issuer Name, DER, exactly as in the certificate. */
        public readonly string $issuer,
        /** The serialNumber INTEGER, complete DER encoding. */
        public readonly string $serial,
    ) {}

    public static function fromDer(string $der): self
    {
        try {
            $cert = Element::parse($der)->expect(Der::SEQUENCE, 'a Certificate');
            $tbs  = $cert->child(0)->expect(Der::SEQUENCE, 'a TBSCertificate');

            // version [0] EXPLICIT is optional (absent means v1).
            $at = $tbs->child(0)->isContext(0) ? 1 : 0;

            $serial = $tbs->child($at)->expect(Der::INTEGER, 'the serialNumber');
            $issuer = $tbs->child($at + 2)->expect(Der::SEQUENCE, 'the issuer Name');
        } catch (DerException $e) {
            throw new InvalidArgumentException('Not a DER X.509 certificate: '.$e->getMessage(), 0, $e);
        }

        return new self($der, $issuer->encoded, $serial->encoded);
    }

    public static function fromPem(string $pem): self
    {
        $blocks = self::pemBlocks($pem);

        if ($blocks === []) {
            throw new InvalidArgumentException('No PEM certificate found.');
        }

        return self::fromDer($blocks[0]);
    }

    /**
     * Every "BEGIN CERTIFICATE" block in a PEM string, as DER. One string may
     * hold a whole chain.
     *
     * @return list<string>
     */
    public static function pemBlocks(string $pem): array
    {
        preg_match_all(
            '/-----BEGIN (?:X509 )?CERTIFICATE-----(.+?)-----END (?:X509 )?CERTIFICATE-----/s',
            $pem,
            $matches,
        );

        $out = [];

        foreach ($matches[1] as $body) {
            $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);

            if ($der === false || $der === '') {
                throw new InvalidArgumentException('A PEM certificate block is not valid base64.');
            }

            $out[] = $der;
        }

        return $out;
    }

    public static function toPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
