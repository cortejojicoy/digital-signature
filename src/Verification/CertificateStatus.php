<?php

namespace Kukux\DigitalSignature\Verification;

use Illuminate\Support\Facades\Cache;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Support\SignatureMode;

/**
 * Whether the certificate behind one signature still stands, as the public
 * verify surfaces report it.
 *
 *   valid      nothing on record revokes it
 *   revoked    the signature, or the certificate it was made with, is revoked
 *   unknown    the hub has no answer for this certificate
 *   unchecked  the hub couldn't be reached; the local answer stands
 *
 * In client mode the hub owns certificates, so it is asked (cached five
 * minutes: a verify page can be reloaded by anyone, and every load would
 * otherwise be a hub call). Otherwise the answer is local.
 */
class CertificateStatus
{
    public function __construct(private readonly HubClient $hub)
    {
    }

    public function of(Signature $signature): string
    {
        return $this->forFingerprint($signature->certificate_fingerprint, $signature->isRevoked());
    }

    /**
     * The same answer from what a DocumentSnapshot slot carries: the
     * certificate's fingerprint, and whether the signature row was revoked.
     */
    public function forFingerprint(?string $fingerprint, bool $signatureRevoked = false): string
    {
        if ($signatureRevoked) {
            return 'revoked';
        }

        $fingerprint = (string) $fingerprint;

        if (SignatureMode::isClient()) {
            return $this->atHub($fingerprint);
        }

        if ($fingerprint !== '') {
            $certificate = UserCertificate::query()->where('fingerprint', $fingerprint)->first();

            if ($certificate?->isRevoked()) {
                return 'revoked';
            }
        }

        return 'valid';
    }

    /** @return 'valid'|'revoked'|'unknown'|'unchecked' */
    public function atHub(?string $fingerprint): string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return 'unknown';
        }

        try {
            return Cache::remember('signature:hub:certificate:'.$fingerprint, 300, function () use ($fingerprint) {
                $status = $this->hub->certificate($fingerprint)['status'] ?? 'unknown';

                return in_array($status, ['valid', 'revoked', 'unknown'], true) ? $status : 'unknown';
            });
        } catch (HubException) {
            return 'unchecked';
        }
    }
}
