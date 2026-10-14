<?php

namespace Kukux\DigitalSignature\Security;

use Illuminate\Support\Facades\Cache;
use Kukux\DigitalSignature\Exceptions\CertificateRevokedException;

/**
 * Validates a certificate against CRL Distribution Points extracted from the cert itself.
 *
 * Behaviour mirrors LibreSign's CrlRevocationChecker:
 *   - CRL bytes are downloaded from each HTTP/HTTPS URL in the cert's CDP extension
 *   - Results are cached (configurable TTL, default 24 h) to avoid repeated downloads
 *   - The check is skipped silently when:
 *       (a) disabled via config
 *       (b) the cert has no CRL Distribution Points  (e.g. self-signed dev certs)
 *       (c) the CRL endpoint is unreachable
 *
 * Requires the `openssl` CLI binary in PATH (same dependency as the PHP openssl extension).
 * Disable in environments without it: SIGNATURE_CRL_ENABLED=false
 */
class CrlValidator
{
    /**
     * @param array $certData Parsed certificate data from openssl_pkcs12_read()
     *                        Must contain a 'cert' key with a PEM string.
     */
    public function validate(array $certData): void
    {
        if (!config('signature.crl.enabled', false)) {
            return;
        }

        $parsed  = openssl_x509_parse($certData['cert']);
        $crlUrls = $this->extractCrlUrls($parsed);

        // Self-signed certs issued by OpenSslDriver will have no CDP — skip gracefully
        if (empty($crlUrls)) {
            return;
        }

        $serial = strtolower($parsed['serialNumberHex'] ?? '');

        if ($serial === '') {
            return;
        }

        foreach ($crlUrls as $url) {
            $this->checkSerialAgainstCrl($url, $serial);
        }
    }

    /**
     * The certificate's status by its CRLs, for a caller that must tell "not
     * revoked" from "couldn't tell" (Agent\AgentCertificateService). Unlike
     * validate() it runs whatever `crl.enabled` says, never throws, and with
     * an issuer only believes a CRL that issuer signed.
     *
     * @return 'good'|'revoked'|'unknown'  unknown: no CRL URL, or no CRL could be fetched, read and verified.
     */
    public function status(string $certPem, ?string $issuerPem = null): string
    {
        $parsed = openssl_x509_parse($certPem);
        $serial = strtolower((string) ($parsed['serialNumberHex'] ?? ''));

        if (! is_array($parsed) || $serial === '') {
            return 'unknown';
        }

        $answered = false;
        $ttlSeconds = (int) config('signature.crl.cache_ttl_hours', 24) * 3600;

        foreach ($this->extractCrlUrls($parsed) as $url) {
            // Only a list that was read (and verified) is cached: an outage
            // must not pass for "not revoked" for a day.
            $cacheKey = 'sig_crl_status_' . md5($url . '|' . ($issuerPem === null ? '' : openssl_x509_fingerprint($issuerPem, 'sha256')));
            $revokedSerials = Cache::get($cacheKey);

            if (! is_array($revokedSerials)) {
                $revokedSerials = $this->fetchRevokedSerials($url, $issuerPem);

                if ($revokedSerials === null) {
                    continue;
                }

                Cache::put($cacheKey, $revokedSerials, $ttlSeconds);
            }

            if (in_array($serial, $revokedSerials, true)) {
                return 'revoked';
            }

            $answered = true;
        }

        return $answered ? 'good' : 'unknown';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function extractCrlUrls(array $parsed): array
    {
        $ext = $parsed['extensions']['crlDistributionPoints'] ?? '';

        // CDP extension value is a multi-line string like:
        //   Full Name:\n  URI:http://crl.example.com/root.crl
        preg_match_all('/URI:(https?:\/\/\S+)/i', $ext, $matches);

        return $matches[1] ?? [];
    }

    private function checkSerialAgainstCrl(string $url, string $serial): void
    {
        $cacheKey = 'sig_crl_' . md5($url);
        $ttlSeconds = (int) config('signature.crl.cache_ttl_hours', 24) * 3600;

        $revokedSerials = Cache::remember($cacheKey, $ttlSeconds, function () use ($url): array {
            return $this->fetchRevokedSerials($url) ?? [];
        });

        if (in_array($serial, $revokedSerials, true)) {
            throw new CertificateRevokedException(
                "Certificate (serial: {$serial}) is listed as revoked in CRL: {$url}"
            );
        }
    }

    /**
     * Download a CRL (DER or PEM), write to a temp file, parse revoked serials via
     * `openssl crl -text -noout`, and return a lowercase-hex array.
     *
     * Returns null on any I/O or parse error, or when $issuerPem is given and
     * did not sign the CRL. validate() treats that as an empty list so the
     * signing can proceed rather than hard-failing due to a network blip.
     */
    protected function fetchRevokedSerials(string $url, ?string $issuerPem = null): ?array
    {
        $der = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10]]));

        if ($der === false || $der === '') {
            return null;
        }

        // Detect PEM vs DER and normalise to PEM
        $pem = str_starts_with(trim($der), '-----BEGIN')
            ? $der
            : "-----BEGIN X509 CRL-----\n"
              . chunk_split(base64_encode($der), 64)
              . "-----END X509 CRL-----\n";

        $crlFile = tempnam(sys_get_temp_dir(), 'sig_crl_');
        file_put_contents($crlFile, $pem);
        $issuerFile = null;

        if ($issuerPem !== null) {
            $issuerFile = tempnam(sys_get_temp_dir(), 'sig_crl_ca_');
            file_put_contents($issuerFile, $issuerPem);
        }

        try {
            return $this->parseRevokedSerials($crlFile, $issuerFile);
        } finally {
            @unlink($crlFile);

            if ($issuerFile !== null) {
                @unlink($issuerFile);
            }
        }
    }

    /**
     * Run `openssl crl -text -noout` and extract every "Serial Number: ..." line.
     * With $issuerFile, the CRL's signature is checked against it too
     * (`-CAfile`, which reports "verify OK").
     */
    private function parseRevokedSerials(string $crlFile, ?string $issuerFile = null): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd     = sprintf('openssl crl -text -noout -in %s', escapeshellarg($crlFile))
            . ($issuerFile === null ? '' : ' -CAfile ' . escapeshellarg($issuerFile));
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ($issuerFile !== null && !str_contains($output . $errors, 'verify OK'))) {
            return null;
        }

        // Serial Number lines look like:
        //   Serial Number: 0A1B2C or Serial Number: 0A:1B:2C
        preg_match_all('/Serial Number:\s*([0-9A-Fa-f:]+)/i', $output, $matches);

        return array_map(
            static fn (string $s): string => strtolower(str_replace(':', '', $s)),
            $matches[1] ?? [],
        );
    }
}
