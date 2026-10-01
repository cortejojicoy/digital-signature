<?php

namespace Kukux\DigitalSignature\Security;

use InvalidArgumentException;

/**
 * Verifies a device's signature over a canonical proof message.
 *
 * One verifier for every kind of device, so the browser and the desktop agent
 * can never disagree about what a valid proof is:
 *
 *   ES256 — P-256 ECDSA with SHA-256. Browsers send raw `r‖s`; the macOS
 *           Secure Enclave and Windows CNG (after conversion) send DER.
 *   RS256 — RSA PKCS#1 v1.5 with SHA-256, from Windows Hello keys.
 *
 * The message every device signs is
 *
 *     v1|<purpose>|<nonce>|<user_id>|<payload_hash>
 *
 * Every field is known to the server before the device answers, so a device
 * can prove possession but cannot smuggle anything into the proof.
 */
final class DeviceProofVerifier
{
    public const VERSION = 'v1';

    public static function message(string $purpose, string $nonce, int|string $userId, string $payloadHash): string
    {
        return implode('|', [self::VERSION, $purpose, $nonce, (string) $userId, $payloadHash]);
    }

    /**
     * Parse a base64 SPKI public key into what the rest of the package needs.
     *
     * @return array{pem: string, fingerprint: string, algorithm: string}
     *
     * @throws InvalidArgumentException for anything that is not a P-256 or
     *                                  RSA-2048+ public key.
     */
    public static function parsePublicKey(string $spkiBase64): array
    {
        $der = base64_decode($spkiBase64, true);

        if ($der === false || $der === '' || strlen($der) > 2048) {
            throw new InvalidArgumentException('The device public key is not valid base64 SPKI.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new InvalidArgumentException('The device public key could not be read.');
        }

        $details = openssl_pkey_get_details($key);

        $algorithm = match (true) {
            ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
                && ($details['ec']['curve_name'] ?? null) === 'prime256v1' => 'ES256',
            ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
                && ($details['bits'] ?? 0) >= 2048                         => 'RS256',
            default => throw new InvalidArgumentException(
                'Device keys must be P-256 ECDSA or RSA of at least 2048 bits.'
            ),
        };

        return [
            'pem'         => $pem,
            'fingerprint' => hash('sha256', $der),
            'algorithm'   => $algorithm,
        ];
    }

    /**
     * @param  string  $signature  Raw signature bytes (not base64).
     * @param  string  $format     `raw` (r‖s, Web Crypto) or `der`. Ignored for RS256.
     */
    public static function verify(
        string $publicKeyPem,
        string $algorithm,
        string $message,
        string $signature,
        string $format = 'raw',
    ): bool {
        if ($algorithm === 'ES256' && $format === 'raw') {
            try {
                $signature = EcdsaSignature::rawToDer($signature);
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        if (! in_array($algorithm, ['ES256', 'RS256'], true)) {
            return false;
        }

        return openssl_verify($message, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
