<?php

namespace Kukux\DigitalSignature\Security;

/**
 * Whether an agent's certificate is revoked, by
 * signature.devices.pkcs12.revocation:
 *
 *   ocsp_then_crl  OCSP first; its CRL when OCSP has no answer
 *   crl            its CRL only
 *   off            not asked: `unknown`
 *
 * `unknown` means nobody answered. What to do then is the caller's
 * (`unreachable`); only `revoked` is a confirmed revocation.
 *
 * Resolved from the container, so tests (and apps with their own revocation
 * service) can swap it.
 */
class CertificateRevocationChecker
{
    public function __construct(
        private readonly OcspChecker $ocsp,
        private readonly CrlValidator $crl,
    ) {}

    /**
     * @param  list<string>  $trustPems  The issuer up to the root.
     * @return 'good'|'revoked'|'unknown'
     */
    public function check(string $certPem, string $issuerPem, array $trustPems = []): string
    {
        $mode = self::mode();

        if ($mode === 'off') {
            return 'unknown';
        }

        if ($mode === 'ocsp_then_crl' && ($status = $this->ocsp->status($certPem, $issuerPem, $trustPems)) !== null) {
            return $status;
        }

        return $this->crl->status($certPem, $issuerPem);
    }

    /** @return 'ocsp_then_crl'|'crl'|'off' */
    public static function mode(): string
    {
        $mode = config('signature.devices.pkcs12.revocation', 'ocsp_then_crl');

        return in_array($mode, ['crl', 'off'], true) ? $mode : 'ocsp_then_crl';
    }
}
