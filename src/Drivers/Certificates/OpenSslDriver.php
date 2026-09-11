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

        $csr = openssl_csr_new($dn, $privKey);

        // Sign against the configured CA when there is one. Without it the
        // certificate is self-signed, which every PDF reader reports as
        // "signature validity unknown" — fine for internal tamper-evidence,
        // not for anything a third party has to verify.
        [$caCert, $caKey] = $this->certificateAuthority();

        $cert = openssl_csr_sign(
            $csr,
            $caCert,                 // null → self-signed
            $caKey ?? $privKey,      // a CA-issued cert is signed by the CA's key
            $this->config['cert_lifetime'],
            ['digest_alg' => $this->config['digest_alg']],
            $this->serialNumber(),
        );

        if ($cert === false) {
            throw new RuntimeException('OpenSSL certificate signing failed: '.$this->opensslErrors());
        }

        // Ship the CA alongside the leaf so a reader can build the chain from
        // the PFX alone rather than needing the CA installed out of band.
        $exportOptions = $caCert !== null ? ['extracerts' => [$caCert]] : [];

        openssl_pkcs12_export($cert, $pfxBinary, $privKey, $password, $exportOptions);

        openssl_x509_export_to_file($cert, $tmpPem = tempnam(sys_get_temp_dir(), 'sig'));
        $fingerprint = openssl_x509_fingerprint(file_get_contents($tmpPem), 'sha256');
        @unlink($tmpPem);

        if (!$pfxBinary || !$fingerprint) {
            throw new RuntimeException('OpenSSL certificate generation failed.');
        }

        return ['pfx' => $pfxBinary, 'fingerprint' => $fingerprint];
    }

    /**
     * The signing CA pair, or [null, null] when this host hasn't set one up.
     *
     * "Set one up" means the files are actually there — the shipped config
     * always points `ca_cert_path`/`ca_key_path` at storage/app/certs, so a
     * non-empty path proves nothing. With neither file present the driver
     * self-signs, which is the documented fallback.
     *
     * A *half* present pair, or one that is there but unusable, throws instead:
     * quietly self-signing when an operator believes they installed a CA is the
     * failure nobody notices until a reader refuses to validate documents that
     * were signed in good faith months earlier.
     *
     * @return array{0: \OpenSSLCertificate|null, 1: \OpenSSLAsymmetricKey|null}
     */
    protected function certificateAuthority(): array
    {
        $certPath = $this->config['ca_cert_path'] ?? null;
        $keyPath  = $this->config['ca_key_path'] ?? null;

        $haveCert = filled($certPath) && file_exists($certPath);
        $haveKey  = filled($keyPath) && file_exists($keyPath);

        if (! $haveCert && ! $haveKey) {
            return [null, null];
        }

        if (! $haveCert || ! $haveKey) {
            throw new RuntimeException(sprintf(
                'Signing CA is half-installed: found the %s but not the %s. Generate both '
                .'(see docs/certificates.md) or remove the one that is there to self-sign.',
                $haveCert ? 'certificate' : 'private key',
                $haveCert ? 'private key' : 'certificate',
            ));
        }

        foreach (['certificate' => $certPath, 'private key' => $keyPath] as $label => $path) {
            if (! is_readable($path)) {
                throw new RuntimeException(
                    "Signing CA {$label} at [{$path}] exists but is not readable. Fix its permissions."
                );
            }
        }

        $caCert = openssl_x509_read((string) file_get_contents($certPath));

        if ($caCert === false) {
            throw new RuntimeException("Signing CA certificate at [{$certPath}] could not be parsed: ".$this->opensslErrors());
        }

        $caKey = openssl_pkey_get_private(
            (string) file_get_contents($keyPath),
            $this->config['ca_key_password'] ?? '',
        );

        if ($caKey === false) {
            throw new RuntimeException("Signing CA private key at [{$keyPath}] could not be read: ".$this->opensslErrors());
        }

        if (! openssl_x509_check_private_key($caCert, $caKey)) {
            throw new RuntimeException('Signing CA certificate and private key do not match.');
        }

        return [$caCert, $caKey];
    }

    /**
     * A unique positive serial. Every certificate a CA issues must carry its
     * own — openssl_csr_sign() otherwise defaults every one of them to 0,
     * which makes revocation ambiguous and trips strict validators.
     */
    protected function serialNumber(): int
    {
        return random_int(1, PHP_INT_MAX);
    }

    /** Drain OpenSSL's error queue into one readable line. */
    protected function opensslErrors(): string
    {
        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return $errors === [] ? 'no OpenSSL error reported' : implode('; ', $errors);
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
