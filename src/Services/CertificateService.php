<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Drivers\Certificates\Contracts\CertificateDriver;
use Kukux\DigitalSignature\Events\CertificateIssued;
use Kukux\DigitalSignature\Models\UserCertificate;
use Illuminate\Support\Facades\Storage;

class CertificateService
{
    public function __construct(protected CertificateDriver $driver) {}

    public function getOrCreate(int $userId, string $password): UserCertificate
    {
        $existing = UserCertificate::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        return $existing ?? $this->issue($userId, $password);
    }

    public function issue(int $userId, string $password): UserCertificate
    {
        $cn     = "user-{$userId}@".parse_url(config('app.url'), PHP_URL_HOST);
        $result = $this->driver->issue($cn, $password);

        $disk    = Storage::disk(config('signature.storage_disk'));
        $pfxPath = config('signature.certs_path')."/{$userId}_".time().'.pfx';
        $disk->put($pfxPath, $result['pfx']);

        $cert = UserCertificate::create([
            'user_id'     => $userId,
            'pfx_path'    => $pfxPath,
            'fingerprint' => $result['fingerprint'],
            'driver'      => config('signature.cert_driver'),
            'issued_at'   => now(),
            'expires_at'  => now()->addDays(config('signature.openssl.cert_lifetime', 3650)),
        ]);

        event(new CertificateIssued($cert));

        return $cert;
    }

    public function load(UserCertificate $cert, string $password): array
    {
        $disk = Storage::disk(config('signature.storage_disk'));
        return $this->driver->load($disk->path($cert->pfx_path), $password);
    }

    public function revoke(UserCertificate $cert): void
    {
        $this->driver->revoke($cert->fingerprint);
        $cert->update(['revoked_at' => now()]);
    }
}
