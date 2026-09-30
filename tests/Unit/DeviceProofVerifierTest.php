<?php

use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\EcdsaSignature;
use Kukux\DigitalSignature\Support\DeviceDescriptor;

/**
 * @return array{spki: string, pem: string, key: \OpenSSLAsymmetricKey}
 */
function p256Key(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $pem = openssl_pkey_get_details($key)['key'];

    return ['spki' => spkiFromPem($pem), 'pem' => $pem, 'key' => $key];
}

function spkiFromPem(string $pem): string
{
    return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
}

describe('DeviceProofVerifier', function () {

    it('verifies a proof produced by the browser code (cross-language fixture)', function () {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/device-proof.json'), true);

        $key = DeviceProofVerifier::parsePublicKey($fixture['public_key']);
        $message = DeviceProofVerifier::message('attest', $fixture['nonce'], $fixture['user_id'], $key['fingerprint']);

        expect($key['algorithm'])->toBe('ES256')
            ->and($key['fingerprint'])->toBe($fixture['fingerprint'])
            ->and($message)->toBe($fixture['message'])
            ->and(DeviceProofVerifier::verify(
                $key['pem'], 'ES256', $message, base64_decode($fixture['signature']), 'raw',
            ))->toBeTrue();
    });

    it('rejects the fixture signature over any other message', function () {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/device-proof.json'), true);
        $key = DeviceProofVerifier::parsePublicKey($fixture['public_key']);

        expect(DeviceProofVerifier::verify(
            $key['pem'], 'ES256', $fixture['message'].'x', base64_decode($fixture['signature']), 'raw',
        ))->toBeFalse();
    });

    it('accepts DER ECDSA signatures, as the desktop agent sends them', function () {
        ['spki' => $spki, 'key' => $private] = p256Key();
        $key = DeviceProofVerifier::parsePublicKey($spki);

        openssl_sign('hello', $der, $private, OPENSSL_ALGO_SHA256);

        expect(DeviceProofVerifier::verify($key['pem'], 'ES256', 'hello', $der, 'der'))->toBeTrue()
            ->and(DeviceProofVerifier::verify($key['pem'], 'ES256', 'hello', $der, 'raw'))->toBeFalse();
    });

    it('accepts RS256 signatures from RSA-2048 keys (Windows Hello)', function () {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $key = DeviceProofVerifier::parsePublicKey(spkiFromPem(openssl_pkey_get_details($private)['key']));

        openssl_sign('hello', $sig, $private, OPENSSL_ALGO_SHA256);

        expect($key['algorithm'])->toBe('RS256')
            ->and(DeviceProofVerifier::verify($key['pem'], 'RS256', 'hello', $sig))->toBeTrue();
    });

    it('refuses keys that are neither P-256 nor RSA-2048+', function (array $options) {
        $private = openssl_pkey_new($options);
        $spki = spkiFromPem(openssl_pkey_get_details($private)['key']);

        expect(fn () => DeviceProofVerifier::parsePublicKey($spki))->toThrow(InvalidArgumentException::class);
    })->with([
        'P-384'    => [['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']],
        'RSA-1024' => [['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]],
    ]);

    it('refuses input that is not a public key at all', function () {
        expect(fn () => DeviceProofVerifier::parsePublicKey('not base64 !!'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => DeviceProofVerifier::parsePublicKey(base64_encode('garbage')))->toThrow(InvalidArgumentException::class);
    });
});

describe('EcdsaSignature', function () {

    it('round-trips DER → raw → DER for real signatures', function () {
        ['key' => $private] = p256Key();

        // Enough signatures that r or s with a high bit, and short integers,
        // both come up.
        for ($i = 0; $i < 50; $i++) {
            openssl_sign("message {$i}", $der, $private, OPENSSL_ALGO_SHA256);

            $raw = EcdsaSignature::derToRaw($der);

            expect(strlen($raw))->toBe(64)
                ->and(EcdsaSignature::rawToDer($raw))->toBe($der);
        }
    });

    it('rejects a raw signature of the wrong length', function () {
        expect(fn () => EcdsaSignature::rawToDer(str_repeat("\x01", 63)))->toThrow(InvalidArgumentException::class);
    });
});

describe('DeviceDescriptor', function () {

    it('describes common browsers', function (string $ua, array $hints, string $type, ?string $platform, ?string $browser) {
        $d = DeviceDescriptor::fromUserAgent($ua, $hints);

        expect($d->deviceType)->toBe($type)
            ->and($d->platform)->toBe($platform)
            ->and($d->browser)->toBe($browser);
    })->with([
        'Chrome on Mac' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            [], 'desktop', 'Mac', 'Chrome 131',
        ],
        'Safari on iPhone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            [], 'mobile', 'iPhone', 'Safari 18',
        ],
        'iPad asking for the desktop site' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
            ['touch' => true], 'tablet', 'iPad', 'Safari 18',
        ],
        'Edge on Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
            [], 'desktop', 'Windows', 'Edge 131',
        ],
        'Chrome on an Android phone' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
            [], 'mobile', 'Android', 'Chrome 131',
        ],
        'Chrome on an Android tablet' => [
            'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            [], 'tablet', 'Android', 'Chrome 131',
        ],
        'Firefox on Linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
            [], 'desktop', 'Linux', 'Firefox 132',
        ],
    ]);
});
