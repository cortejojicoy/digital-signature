<?php

namespace Kukux\DigitalSignature\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * A throwaway PKI for the certificate-pairing tests, made with PHP's openssl
 * functions once per process: a root and an issuing CA (like PNPKI's
 * Philippine Root CA - G2 and Government Signing CA - G2), personal
 * certificates under it, and a root nobody trusts.
 *
 * Every certificate is valid from now for a year (CAs for ten); tests travel
 * in time for an expired one.
 */
final class TestCertificates
{
    /** @var array<string, mixed>|null */
    private static ?array $pki = null;

    private static int $serial = 100;

    /**
     * @return array{
     *     root_path: string, root: array, intermediate: array, other_root: array,
     *     leaf: array, second: array, p256: array, encipher: array, ca_leaf: array, stranger: array,
     * }  Each certificate: key, cert, pem, der (base64), spki (base64), fingerprint.
     */
    public static function pki(): array
    {
        return self::$pki ??= self::build();
    }

    /** The claim's `certificate_chain` for $leaf: leaf first, base64 DER. */
    public static function chain(array ...$certificates): array
    {
        return array_map(fn (array $c) => $c['der'], $certificates);
    }

    private static function build(): array
    {
        $config = tempnam(sys_get_temp_dir(), 'dsig_pki_');
        file_put_contents($config, <<<'CNF'
            [req]
            distinguished_name = dn
            [dn]
            [ca]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            [leaf]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature,nonRepudiation
            authorityInfoAccess = OCSP;URI:http://ocsp.test.invalid
            crlDistributionPoints = URI:http://crl.test.invalid/signing-ca.crl
            [leaf_encipher]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,keyEncipherment
            CNF);

        $root = self::issue('Kukux Test Root CA', null, 'ca', 'ec', 3650, $config);
        $intermediate = self::issue('Kukux Test Signing CA', $root, 'ca', 'ec', 3650, $config);
        $otherRoot = self::issue('Someone Else Root CA', null, 'ca', 'ec', 3650, $config);

        $rootPath = tempnam(sys_get_temp_dir(), 'dsig_root_');
        file_put_contents($rootPath, base64_decode($root['der']));   // DER, like the bundled .cer
        register_shutdown_function(fn () => array_map(fn (string $f) => @unlink($f), [$config, $rootPath]));

        return [
            'root_path'    => $rootPath,
            'root'         => $root,
            'intermediate' => $intermediate,
            'other_root'   => $otherRoot,
            'leaf'         => self::issue('Test UP 4', $intermediate, 'leaf', 'rsa', 365, $config),
            'second'       => self::issue('Test UP 4', $intermediate, 'leaf', 'rsa', 365, $config),
            'p256'         => self::issue('Test UP 4', $intermediate, 'leaf', 'ec', 365, $config),
            'encipher'     => self::issue('Test UP 4', $intermediate, 'leaf_encipher', 'rsa', 365, $config),
            'ca_leaf'      => self::issue('Test UP 4', $intermediate, 'ca', 'rsa', 365, $config),
            'stranger'     => self::issue('Test UP 4', $otherRoot, 'leaf', 'rsa', 365, $config),
        ];
    }

    /**
     * @param  array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate}|null  $issuer  null: self-signed.
     */
    private static function issue(string $commonName, ?array $issuer, string $extensions, string $type, int $days, string $config): array
    {
        $key = openssl_pkey_new($type === 'rsa'
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $options = ['config' => $config, 'x509_extensions' => $extensions, 'digest_alg' => 'sha256'];
        $dn = ['countryName' => 'PH', 'organizationName' => 'Kukux Test', 'commonName' => $commonName];
        $csr = openssl_csr_new($dn, $key, $options);
        $cert = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, $days, $options, self::$serial++);

        openssl_x509_export($cert, $pem);
        $spki = preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($key)['key']);

        return [
            'key'         => $key,
            'cert'        => $cert,
            'pem'         => $pem,
            'der'         => preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem),
            'spki'        => $spki,
            'fingerprint' => openssl_x509_fingerprint($cert, 'sha256'),
        ];
    }
}
