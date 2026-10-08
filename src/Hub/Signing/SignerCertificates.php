<?php

namespace Kukux\DigitalSignature\Hub\Signing;

use Kukux\DigitalSignature\Models\UserCertificate;

/**
 * The state of a hub account's signing certificate, as the API reports it.
 * Issuing and revoking stay with Services\CertificateService.
 */
class SignerCertificates
{
    /** The certificate a signing would use now: unrevoked and unexpired. */
    public function current(int $userId): ?UserCertificate
    {
        return UserCertificate::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * True when the account's latest certificate was revoked and nothing
     * valid replaced it. Signing then must stop: CertificateService would
     * otherwise quietly issue a fresh certificate and sign anyway.
     */
    public function isRevoked(int $userId): bool
    {
        if ($this->current($userId) !== null) {
            return false;
        }

        $latest = UserCertificate::query()->where('user_id', $userId)->latest('id')->first();

        return $latest?->isRevoked() ?? false;
    }

    public function find(string $fingerprint): ?UserCertificate
    {
        // OpenSSL writes lowercase hex; other drivers may not.
        return UserCertificate::query()
            ->whereIn('fingerprint', [strtolower($fingerprint), strtoupper($fingerprint)])
            ->first();
    }
}
