<?php

namespace Kukux\DigitalSignature\Drivers\Certificates;

use Kukux\DigitalSignature\Drivers\Certificates\Contracts\CertificateDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CfsslDriver implements CertificateDriver
{
    public function __construct(protected array $config) {}

    public function issue(string $commonName, string $password): array
    {
        $response = Http::post("{$this->config['host']}/api/v1/cfssl/newcert", [
            'request' => [
                'CN'      => $commonName,
                'hosts'   => [],
                'profile' => $this->config['profile'],
                'key'     => ['algo' => 'rsa', 'size' => 2048],
            ],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('CFSSL certificate issuance failed: '.$response->body());
        }

        $result = $response->json('result');

        // Combine cert + key into PKCS12
        $cert    = openssl_x509_read($result['certificate']);
        $privKey = openssl_pkey_get_private($result['private_key']);
        openssl_pkcs12_export($cert, $pfxBinary, $privKey, $password);

        $fingerprint = openssl_x509_fingerprint($result['certificate'], 'sha256');

        return ['pfx' => $pfxBinary, 'fingerprint' => $fingerprint];
    }

    public function load(string $pfxPath, string $password): mixed
    {
        $pfx = file_get_contents($pfxPath);
        if (!openssl_pkcs12_read($pfx, $certs, $password)) {
            throw new RuntimeException('Failed to load CFSSL-issued PFX.');
        }
        return $certs;
    }

    public function revoke(string $fingerprint): void
    {
        Http::post("{$this->config['host']}/api/v1/cfssl/revoke", [
            'serial'  => $fingerprint,
            'reason'  => 'unspecified',
        ]);
    }
}