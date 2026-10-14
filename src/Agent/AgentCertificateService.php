<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\AgentCertificateValidator;
use Kukux\DigitalSignature\Security\CertificateRevocationChecker;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Agents whose identity key was imported from a certificate file (key_source
 * `pkcs12`, digital-signature-agent pkcs12-key-storage-plan.md §9), under
 * signature.devices.pkcs12:
 *
 *   check()   claim     chain, subject binding and revocation; the device's
 *                       certificate columns
 *   holder()  claim and confirm  another active computer with this certificate
 *   recheck() job       still in date, and not revoked since it was last asked
 *
 * Refusals are the protocol's claim errors, which the agent words for the
 * user: certificates_disabled, certificate_untrusted, certificate_expired,
 * certificate_revoked, certificate_subject_mismatch, certificate_in_use.
 */
class AgentCertificateService
{
    /** subject_binding values that are built. admin_approval is planned. */
    public const BINDINGS = ['none', 'name_match'];

    public function __construct(
        private readonly AgentCertificateValidator $validator,
        private readonly CertificateRevocationChecker $revocation,
        private readonly DeviceRegistry $registry,
    ) {}

    /** Whether this app pairs certificate keys at all: lookup's `accepts_certificates`. */
    public static function enabled(): bool
    {
        return (bool) config('signature.devices.pkcs12.enabled', false)
            && in_array(config('signature.devices.pkcs12.subject_binding', 'none'), self::BINDINGS, true);
    }

    /**
     * Everything about the certificate except who else holds it.
     *
     * @return array<string, mixed>  The device's certificate columns, as kept in the claim.
     *
     * @throws AgentApiException
     */
    public function check(mixed $chain, string $identityPublicKey, ?object $user): array
    {
        if (! (bool) config('signature.devices.pkcs12.enabled', false)) {
            throw new AgentApiException(422, 'certificates_disabled', "This app doesn't accept software certificates.");
        }

        if (! self::enabled()) {
            throw new AgentApiException(422, 'certificates_disabled', "This app can't take software certificates yet: an administrator has to confirm each one, and that isn't available.");
        }

        $certificate = $this->validator->validate($chain, $identityPublicKey);

        if (config('signature.devices.pkcs12.subject_binding') === 'name_match') {
            $name = (string) ($user->name ?? '');

            if ($name === '' || self::normalise($certificate['common_name']) !== self::normalise($name)) {
                throw new AgentApiException(422, 'certificate_subject_mismatch', "This certificate is for {$certificate['common_name']}, not {$name}.");
            }
        }

        $status = $this->revocationStatus($certificate);

        return [
            'key_source'              => 'pkcs12',
            'certificate_fingerprint' => $certificate['fingerprint'],
            'certificate_serial'      => Str::limit($certificate['serial'], 128, ''),
            'certificate_issuer'      => $certificate['issuer'],
            'certificate_subject'     => $certificate['subject'],
            'certificate_not_after'   => $certificate['not_after']->toIso8601String(),
            'certificate_chain'       => $certificate['chain'],
            'certificate_checked_at'  => $status === 'unknown' ? null : now()->toIso8601String(),
            'certificate_status'      => $status,
        ];
    }

    /**
     * Another active agent device with this certificate, or this key, when
     * one_device_per_certificate is on. $same is the device a re-pair updates,
     * which may keep it.
     */
    public function holder(string $certificateFingerprint, string $keyFingerprint, ?SigningDevice $same, bool $lock = false): ?SigningDevice
    {
        if (! (bool) config('signature.devices.pkcs12.one_device_per_certificate', true)) {
            return null;
        }

        return SigningDevice::query()
            ->where('kind', 'agent')
            ->active()
            ->where(fn ($q) => $q->where('certificate_fingerprint', $certificateFingerprint)->orWhere('key_fingerprint', $keyFingerprint))
            ->when($same, fn ($q) => $q->whereKeyNot($same->id))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /**
     * Before a certificate device's approval counts: the certificate is still
     * in date and trusted, and, once recheck_ttl has passed since the last
     * definite answer, not revoked. A confirmed revocation revokes the device.
     *
     * @throws AgentApiException
     */
    public function recheck(SigningDevice $device): void
    {
        if (! $device->usesCertificate()) {
            return;
        }

        if ($device->certificate_not_after === null || $device->certificate_not_after->isPast()) {
            throw new AgentApiException(422, 'certificate_expired', 'The certificate on this computer has expired. Import a new one, then pair again.');
        }

        $ttl = (int) config('signature.devices.pkcs12.recheck_ttl', 86400);

        if ($device->certificate_checked_at !== null && $device->certificate_checked_at->gt(now()->subSeconds($ttl))) {
            return;
        }

        // The chain again: a root this app no longer trusts stops it too.
        $spki = preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) $device->public_key);
        $certificate = $this->validator->validate($device->certificate_chain, (string) $spki);

        try {
            $status = $this->revocationStatus($certificate);
        } catch (AgentApiException $e) {
            if ($e->errorCode === 'certificate_revoked') {
                $device->forceFill(['certificate_status' => 'revoked', 'certificate_checked_at' => now()])->save();
                $this->registry->revoke($device);

                SignatureAudit::record(SignatureAudit::AGENT_CERTIFICATE_REVOKED, [
                    'subject_user_id' => $device->user_id,
                    'device_id'       => $device->id,
                    'context'         => ['certificate' => $device->certificate_fingerprint, 'serial' => $device->certificate_serial],
                ]);
            }

            throw $e;
        }

        $device->forceFill(array_filter([
            'certificate_status'     => $status,
            'certificate_checked_at' => $status === 'unknown' ? null : now(),
        ]))->save();
    }

    /**
     * @param  array{leaf_pem: string, issuer_pem: string, trust_pems: list<string>}  $certificate
     * @return 'good'|'unknown'
     *
     * @throws AgentApiException  revoked, or nobody answered under `unreachable = deny`.
     */
    private function revocationStatus(array $certificate): string
    {
        $status = $this->revocation->check($certificate['leaf_pem'], $certificate['issuer_pem'], $certificate['trust_pems']);

        if ($status === 'revoked') {
            throw new AgentApiException(422, 'certificate_revoked', 'This certificate has been revoked by its issuer.');
        }

        if ($status !== 'good'
            && CertificateRevocationChecker::mode() !== 'off'
            && config('signature.devices.pkcs12.unreachable', 'allow') === 'deny') {
            throw new AgentApiException(503, 'revocation_unavailable', "The certificate's issuer couldn't be reached to check it isn't revoked. Try again later.");
        }

        return $status === 'good' ? 'good' : 'unknown';
    }

    /** "José P. Rizal" and "JOSE P RIZAL" are the same name. */
    private static function normalise(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
    }
}
