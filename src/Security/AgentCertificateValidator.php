<?php

namespace Kukux\DigitalSignature\Security;

use Carbon\CarbonImmutable;
use Kukux\DigitalSignature\Agent\AgentApiException;
use OpenSSLCertificate;

/**
 * The certificate chain an agent sends when its identity key was imported
 * from a PKCS#12 file (digital-signature-agent pkcs12-key-storage-plan.md
 * §9.2), checked the way a relying party checks it:
 *
 *   - the leaf parses, isn't a CA, may sign (keyUsage digitalSignature or
 *     nonRepudiation, when the extension is there) and is in date;
 *   - its key is the identity key the claim was proven with;
 *   - each certificate is issued and signed by the next, which is a CA
 *     within its path length;
 *   - the top is one of signature.devices.pkcs12.trusted_roots, or issued
 *     by one.
 *
 * Revocation is CertificateRevocationChecker's. Refusals are the protocol's
 * claim errors: `certificate_untrusted` or `certificate_expired`.
 */
class AgentCertificateValidator
{
    /** Philippine Root CA - G2 (DICT), bundled. SHA-256 DE:CD:32:E1:…:E0:F6:B9. */
    public const PNPKI_ROOT = __DIR__.'/../../resources/pnpki/philippine-root-ca-g2.cer';

    private const MAX_CHAIN = 8;

    private const MAX_CERT_BYTES = 16384;

    /**
     * @param  mixed  $chain  The claim's `certificate_chain`: base64 DER, leaf first.
     * @param  string  $identityPublicKey  The claim's base64 SPKI.
     * @return array{
     *     leaf_pem: string, issuer_pem: string, trust_pems: list<string>, chain: list<string>,
     *     fingerprint: string, serial: string, subject: string, issuer: string,
     *     common_name: string, not_after: CarbonImmutable,
     * }  trust_pems: the issuer up to the root, for checking an OCSP or CRL signer.
     *
     * @throws AgentApiException
     */
    public function validate(mixed $chain, string $identityPublicKey): array
    {
        $certs = $this->read($chain);
        $leaf = $certs[0];

        if (self::isCa($leaf['parsed'])) {
            throw self::untrusted('The certificate sent is a CA certificate, not a personal one.');
        }

        $usage = $leaf['parsed']['extensions']['keyUsage'] ?? null;

        if ($usage !== null && ! preg_match('/Digital Signature|Non Repudiation|Content Commitment/i', $usage)) {
            throw self::untrusted("This certificate isn't for signing (its key usage is {$usage}).");
        }

        if (self::spki($leaf['cert']) !== base64_decode($identityPublicKey, true)) {
            throw self::untrusted("The certificate's key is not the identity key.");
        }

        if (! self::inDate($leaf['parsed'])) {
            throw new AgentApiException(422, 'certificate_expired', 'This certificate has expired, or is not valid yet.');
        }

        // Each link: the next certificate issued and signed this one, and may issue.
        for ($i = 1; $i < count($certs); $i++) {
            if (! self::issued($certs[$i - 1], $certs[$i]) || ! self::canIssue($certs[$i]['parsed'], $i - 1)) {
                throw self::untrusted('The certificate chain is broken.');
            }
        }

        $top = $certs[count($certs) - 1];
        $anchor = $this->anchor($top, count($certs) - 1);

        if ($anchor === null) {
            throw self::untrusted('The certificate is not from an issuer this app trusts.');
        }

        $trust = array_map(fn (array $c) => $c['pem'], array_slice($certs, 1));

        if ($anchor['fingerprint'] !== $top['fingerprint']) {
            $trust[] = $anchor['pem'];
        }

        return [
            'leaf_pem'    => $leaf['pem'],
            'issuer_pem'  => $trust[0] ?? $leaf['pem'],
            'trust_pems'  => $trust,
            'chain'       => array_map(fn (array $c) => base64_encode($c['der']), $certs),
            'fingerprint' => $leaf['fingerprint'],
            'serial'      => strtoupper((string) ($leaf['parsed']['serialNumberHex'] ?? '')),
            'subject'     => self::dn($leaf['parsed']['subject'] ?? []),
            'issuer'      => self::dn($leaf['parsed']['issuer'] ?? []),
            'common_name' => self::first($leaf['parsed']['subject']['CN'] ?? ''),
            'not_after'   => CarbonImmutable::createFromTimestamp((int) $leaf['parsed']['validTo_time_t']),
        ];
    }

    /**
     * signature.devices.pkcs12.trusted_roots, read. A path that can't be read
     * trusts nothing.
     *
     * @return list<array{cert: OpenSSLCertificate, pem: string, der: string, parsed: array<string, mixed>, fingerprint: string}>
     */
    public function roots(): array
    {
        $paths = config('signature.devices.pkcs12.trusted_roots', [self::PNPKI_ROOT]);
        $paths = is_array($paths) ? $paths : explode(',', (string) $paths);
        $roots = [];

        foreach ($paths as $path) {
            $bytes = is_string($path) && is_file(trim($path)) ? (string) file_get_contents(trim($path)) : '';
            $der = str_contains($bytes, '-----BEGIN CERTIFICATE-----') ? self::pemToDer($bytes) : $bytes;

            if ($der !== '' && ($root = self::parse($der)) !== null) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * The common name in an RFC 4514 DN made by dn(), e.g. "Test UP 4".
     */
    public static function commonName(?string $dn): ?string
    {
        foreach (preg_split('/(?<!\\\\),\s*/', (string) $dn) ?: [] as $part) {
            if (str_starts_with($part, 'CN=')) {
                return stripcslashes(substr($part, 3));
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return non-empty-list<array{cert: OpenSSLCertificate, pem: string, der: string, parsed: array<string, mixed>, fingerprint: string}>
     *
     * @throws AgentApiException
     */
    private function read(mixed $chain): array
    {
        if (! is_array($chain) || $chain === [] || count($chain) > self::MAX_CHAIN || ! array_is_list($chain)) {
            throw self::untrusted('No certificate chain was sent.');
        }

        $certs = [];

        foreach ($chain as $item) {
            $der = is_string($item) && strlen($item) <= self::MAX_CERT_BYTES * 2 ? base64_decode($item, true) : false;
            $cert = is_string($der) && $der !== '' && strlen($der) <= self::MAX_CERT_BYTES ? self::parse($der) : null;

            if ($cert === null) {
                throw self::untrusted('The certificate chain could not be read.');
            }

            $certs[] = $cert;
        }

        return $certs;
    }

    /**
     * @return array{cert: OpenSSLCertificate, pem: string, der: string, parsed: array<string, mixed>, fingerprint: string}|null
     */
    private static function parse(string $der): ?array
    {
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
        $cert = @openssl_x509_read($pem);
        $parsed = $cert === false ? false : openssl_x509_parse($cert);

        if ($cert === false || ! is_array($parsed) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            return null;
        }

        return [
            'cert'        => $cert,
            'pem'         => $pem,
            'der'         => $der,
            'parsed'      => $parsed,
            'fingerprint' => (string) openssl_x509_fingerprint($cert, 'sha256'),
        ];
    }

    /**
     * The trusted root that is $top, or that issued it.
     *
     * @param  array{cert: OpenSSLCertificate, parsed: array<string, mixed>, fingerprint: string}  $top
     * @param  int  $below  CA certificates between $top and the leaf, $top included.
     * @return array{pem: string, fingerprint: string}|null
     */
    private function anchor(array $top, int $below): ?array
    {
        foreach ($this->roots() as $root) {
            if ($root['fingerprint'] === $top['fingerprint']) {
                return $root;
            }

            if (self::canIssue($root['parsed'], $below) && self::issued($top, $root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param  array{cert: OpenSSLCertificate, parsed: array<string, mixed>}  $child
     * @param  array{cert: OpenSSLCertificate, parsed: array<string, mixed>}  $parent
     */
    private static function issued(array $child, array $parent): bool
    {
        return ($child['parsed']['issuer'] ?? null) == ($parent['parsed']['subject'] ?? [])
            && openssl_x509_verify($child['cert'], $parent['cert']) === 1;
    }

    /**
     * A CA in date, allowed to sign certificates, with $below CA
     * certificates between it and the leaf.
     *
     * @param  array<string, mixed>  $parsed
     */
    private static function canIssue(array $parsed, int $below): bool
    {
        $usage = $parsed['extensions']['keyUsage'] ?? null;
        $pathLen = preg_match('/pathlen:(\d+)/i', (string) ($parsed['extensions']['basicConstraints'] ?? ''), $m) ? (int) $m[1] : null;

        return self::isCa($parsed)
            && self::inDate($parsed)
            && ($usage === null || str_contains($usage, 'Certificate Sign'))
            && ($pathLen === null || $below <= $pathLen);
    }

    /** @param  array<string, mixed>  $parsed */
    private static function isCa(array $parsed): bool
    {
        return (bool) preg_match('/CA:TRUE/i', (string) ($parsed['extensions']['basicConstraints'] ?? ''));
    }

    /** @param  array<string, mixed>  $parsed */
    private static function inDate(array $parsed): bool
    {
        $now = now()->getTimestamp();

        return $now >= (int) $parsed['validFrom_time_t'] && $now <= (int) $parsed['validTo_time_t'];
    }

    /** The certificate's SubjectPublicKeyInfo, DER. */
    private static function spki(OpenSSLCertificate $cert): string
    {
        $key = openssl_pkey_get_public($cert);
        $pem = $key === false ? '' : (string) (openssl_pkey_get_details($key)['key'] ?? '');

        return (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
    }

    /**
     * openssl_x509_parse()'s name, most specific first (RFC 4514):
     * "CN=Test UP 4, O=…, C=PH".
     *
     * @param  array<string, string|list<string>>  $name
     */
    private static function dn(array $name): string
    {
        $parts = [];

        foreach ($name as $attribute => $values) {
            foreach ((array) $values as $value) {
                $parts[] = $attribute.'='.addcslashes((string) $value, ',+"\\<>;');
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /** @param  string|list<string>  $value */
    private static function first(string|array $value): string
    {
        return is_array($value) ? (string) ($value[0] ?? '') : $value;
    }

    private static function pemToDer(string $pem): string
    {
        return preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)
            ? (string) base64_decode((string) preg_replace('/\s+/', '', $m[1]))
            : '';
    }

    private static function untrusted(string $message): AgentApiException
    {
        return new AgentApiException(422, 'certificate_untrusted', $message);
    }
}
