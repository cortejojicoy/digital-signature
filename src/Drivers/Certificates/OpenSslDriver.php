<?php

namespace Kukux\DigitalSignature\Drivers\Certificates;

use Kukux\DigitalSignature\Drivers\Certificates\Contracts\CertificateDriver;
use RuntimeException;

class OpenSslDriver implements CertificateDriver
{
    public function __construct(protected array $config) {}

    public function issue(string $commonName, string $password): array
    {
        $dn = [
            'commonName'   => $commonName,
            'countryName'  => 'PH',
            'organizationName' => config('app.name'),
        ];

        $privKey = openssl_pkey_new([
            'digest_alg'       => $this->config['digest_alg'],
            'private_key_bits' => $this->config['private_key_bits'],
            'private_key_type' => $this->config['private_key_type'],
        ]);

        $csr  = openssl_csr_new($dn, $privKey);
        $cert = openssl_csr_sign(
            $csr,
            null,         // self-signed; replace with CA cert for chain signing
            $privKey,
            $this->config['cert_lifetime'],
            ['digest_alg' => $this->config['digest_alg']],
        );

        openssl_pkcs12_export($cert, $pfxBinary, $privKey, $password);

        openssl_x509_export_to_file($cert, $tmpPem = tempnam(sys_get_temp_dir(), 'sig'));
        $fingerprint = openssl_x509_fingerprint(file_get_contents($tmpPem), 'sha256');
        @unlink($tmpPem);

        if (!$pfxBinary || !$fingerprint) {
            throw new RuntimeException('OpenSSL certificate generation failed.');
        }

        return ['pfx' => $pfxBinary, 'fingerprint' => $fingerprint];
    }

    public function load(string $pfxPath, string $password): mixed
    {
        $pfx = file_get_contents($pfxPath);
        if (!openssl_pkcs12_read($pfx, $certs, $password)) {
            throw new RuntimeException('Failed to load PFX certificate.');
        }
        return $certs;
    }

    public function revoke(string $fingerprint): void
    {
        // In production: append to CRL or call OCSP endpoint.
        // For MVP: simply mark revoked in DB (handled by CertificateService).
    }
}