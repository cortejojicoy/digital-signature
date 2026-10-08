<?php

use Illuminate\Support\Carbon;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Hub\Cms\CmsException;
use Kukux\DigitalSignature\Hub\Cms\CmsSigner;
use Kukux\DigitalSignature\Hub\Cms\SignedDataReader;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Support\Der\Oid;
use Kukux\DigitalSignature\Support\Der\X509;
use Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys;

/**
 * Detached CMS over a precomputed digest. Each signature is checked two
 * independent ways: by re-deriving the signed bytes in PHP and verifying with
 * openssl_verify(), and by OpenSSL's own `cms -verify` against the content
 * the digest was taken from.
 */
function cmsOver(string $content, array $identity, ?string $tsaUrl = null): string
{
    return app(DigestSigner::class)->signDigest(
        hash('sha256', $content),
        $identity['cert'],
        $identity['key'],
        $identity['chain'],
        $tsaUrl,
    );
}

describe('CmsSigner', function () {

    it('is what DigestSigner resolves to', function () {
        expect(app(DigestSigner::class))->toBeInstanceOf(CmsSigner::class);
    });

    foreach (['rsa', 'ec'] as $type) {

        it("signs a digest with an {$type} key, verifiable in pure PHP", function () use ($type) {
            Carbon::setTestNow('2026-10-08 01:02:03');

            $identity = CmsTestKeys::$type();
            $content  = "document bytes covered by /ByteRange\n".str_repeat('x', 1000);
            $cms      = SignedDataReader::parse(cmsOver($content, $identity));

            $signerDer = X509::fromPem($identity['cert'])->der;

            expect($cms->messageDigest())->toBe(hash('sha256', $content, true))
                ->and($cms->digestAlgorithm)->toBe(Oid::SHA256)
                ->and($cms->signatureAlgorithm)->toBe($type === 'rsa' ? Oid::RSA_ENCRYPTION : Oid::ECDSA_WITH_SHA256)
                ->and($cms->signerCertificate())->toBe($signerDer)
                ->and($cms->certificates)->toContain(X509::fromPem($identity['chain'][0])->der)
                ->and($cms->verifiesWithSignerCertificate())->toBeTrue()
                ->and($cms->timestampToken())->toBeNull();

            // And independently of SignedDataReader::verifiesWith…: the exact
            // signed bytes, the certificate's public key, openssl_verify.
            expect(openssl_verify($cms->signedAttributesDer, $cms->signature, openssl_pkey_get_public($identity['cert']), OPENSSL_ALGO_SHA256))
                ->toBe(1);

            // contentType id-data, signingTime now, signingCertificateV2 binds the cert.
            $attrs = $cms->signedAttributes;
            expect($attrs[Oid::CONTENT_TYPE][0]->oid())->toBe(Oid::DATA)
                ->and($attrs[Oid::SIGNING_TIME][0]->content)->toBe('261008010203Z');

            $essCertId = $attrs[Oid::SIGNING_CERTIFICATE_V2][0]->child(0)->child(0);
            expect($essCertId->child(0)->content)->toBe(hash('sha256', $signerDer, true))
                ->and($essCertId->child(1)->child(1)->encoded)->toBe(X509::fromDer($signerDer)->serial);

            // Signed attributes are in DER (sorted) order — a verifier
            // re-encodes them, so any other order breaks the signature.
            $encodings = array_map(
                fn (Element $a): string => $a->encoded,
                Element::parse($cms->signedAttributesDer)->children(),
            );
            $sorted = $encodings;
            sort($sorted, SORT_STRING);
            expect($encodings)->toBe($sorted);

            // A tampered digest no longer verifies.
            $other = SignedDataReader::parse(cmsOver('something else', $identity));
            expect(openssl_verify($cms->signedAttributesDer, $other->signature, openssl_pkey_get_public($identity['cert']), OPENSSL_ALGO_SHA256))
                ->toBe(0);
        });

        it("passes openssl cms -verify with an {$type} key", function () use ($type) {
            if (CmsTestKeys::openssl() === null) {
                $this->markTestSkipped('No openssl binary with a working `cms` command (looked in /opt/homebrew/bin, /usr/local/bin, /usr/bin).');
            }

            $content = random_bytes(4096);
            [$ok, $output] = CmsTestKeys::opensslVerify(cmsOver($content, CmsTestKeys::$type()), $content);

            expect($ok)->toBeTrue($output)
                ->and($output)->toContain('Verification successful');

            // And it is the content that is being checked, not just the structure.
            [$ok] = CmsTestKeys::opensslVerify(cmsOver($content, CmsTestKeys::$type()), $content.'!');
            expect($ok)->toBeFalse();
        });
    }

    it('accepts the key as an OpenSSLAsymmetricKey', function () {
        $identity = CmsTestKeys::rsa();
        $der = app(CmsSigner::class)->signDigest(
            str_repeat('ab', 32),
            $identity['cert'],
            openssl_pkey_get_private($identity['key']),
        );

        expect(SignedDataReader::parse($der)->verifiesWithSignerCertificate())->toBeTrue();
    });

    it('rejects a digest that is not 64 hex characters', function (string $digest) {
        $identity = CmsTestKeys::rsa();

        expect(fn () => app(CmsSigner::class)->signDigest($digest, $identity['cert'], $identity['key']))
            ->toThrow(CmsException::class, '64 hexadecimal');
    })->with([
        'too short' => [str_repeat('a', 63)],
        'too long'  => [str_repeat('a', 65)],
        'not hex'   => [str_repeat('g', 64)],
        'binary'    => [random_bytes(32)],
    ]);

    it('rejects a key that does not belong to the certificate', function () {
        expect(fn () => app(CmsSigner::class)->signDigest(str_repeat('0', 64), CmsTestKeys::rsa()['cert'], CmsTestKeys::ec()['key']))
            ->toThrow(CmsException::class, 'does not belong');
    });

    it('rejects unreadable keys and certificates', function () {
        $identity = CmsTestKeys::rsa();
        $signer   = app(CmsSigner::class);
        $digest   = str_repeat('0', 64);

        expect(fn () => $signer->signDigest($digest, $identity['cert'], 'not a key'))->toThrow(CmsException::class, 'could not be read')
            ->and(fn () => $signer->signDigest($digest, $identity['cert'], 42))->toThrow(CmsException::class, 'PEM string')
            ->and(fn () => $signer->signDigest($digest, $identity['cert'], openssl_pkey_get_public($identity['cert'])))->toThrow(CmsException::class, 'not a private key')
            ->and(fn () => $signer->signDigest($digest, 'not a cert', $identity['key']))->toThrow(CmsException::class, 'BEGIN CERTIFICATE');
    });
});
