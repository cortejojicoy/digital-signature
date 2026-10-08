<?php

namespace Kukux\DigitalSignature\Hub\Specimens;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Services\CertificateService;
use Kukux\DigitalSignature\Services\SignatureManager;

/**
 * Revoke a person's signature at the hub (admin panel, separation, fraud).
 *
 *   1. The master signature is revoked (SignatureManager::revoke(), which
 *      fires the usual SignatureRevoked event).
 *   2. The account's certificates are revoked.
 *   3. Every app's mirror object `<prefix>/<hub_uuid>.png` on
 *      `hub.mirrors_disk` is deleted by the hub itself (R4), so a missed
 *      webhook can't leave an image behind.
 *   4. Holder apps get `signature.revoked`.
 *   5. One `signature.revoked` audit row.
 *
 * Sign requests still pending are refused when the agent approves them: the
 * approval-time check finds no active specimen.
 */
class HubRevocation
{
    public function __construct(
        private readonly People $people,
        private readonly HubNotifier $notifier,
    ) {}

    /**
     * @param  array{from: string, to: string}|null  $window  Signatures in this window are suspect (sent to apps).
     */
    public function revokeSignature(int $userId, string $reason, ?int $actorId = null, ?array $window = null): void
    {
        $signatures = Signature::query()
            ->primaryActiveFor($userId)
            ->where('source', '!=', 'hub')
            ->get();

        foreach ($signatures as $signature) {
            app(SignatureManager::class)->revoke($signature);
        }

        $revokedCertificates = $this->revokeCertificates($userId);

        $deleted = $this->deleteMirrors($signatures->pluck('uuid')->filter()->all());

        $personnelKey = $this->people->personnelKeyFor($userId);

        if ($personnelKey !== null) {
            $this->notifier->signatureRevoked($personnelKey, $reason, $window);
        }

        SignatureAudit::record(SignatureAudit::SIGNATURE_REVOKED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => $actorId,
            'actor_type'      => $actorId !== null ? 'user' : 'system',
            'signature_id'    => $signatures->first()?->id,
            'personnel_key'   => $personnelKey,
            'context'         => array_filter([
                'reason'                 => $reason,
                'signatures'             => $signatures->pluck('uuid')->all(),
                'certificates'           => $revokedCertificates,
                'mirror_objects_deleted' => $deleted,
                'window'                 => $window,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * Delete `<prefix>/<uuid>.png` in every registered app's prefix. Every
     * app rather than only current holders: an app unlinked since it pulled
     * may still have a copy. Deleting a missing object is a no-op.
     *
     * @param  array<int, string>  $uuids
     * @return array<int, string>  the paths deleted (or already absent)
     */
    public function deleteMirrors(array $uuids): array
    {
        $diskName = config('signature.hub.mirrors_disk');

        if (blank($diskName) || $uuids === []) {
            return [];
        }

        $disk = Storage::disk($diskName);
        $paths = [];

        foreach (HubApp::query()->get() as $app) {
            $prefix = trim((string) ($app->mirror_prefix ?: $app->client_id), '/');

            foreach ($uuids as $uuid) {
                $paths[] = "{$prefix}/{$uuid}.png";
            }
        }

        try {
            $disk->delete($paths);
        } catch (\Throwable $e) {
            // Revocation itself must not fail on storage: the signature and
            // certificate are already revoked and the hub refuses at sign
            // time. `signature:hub-audit-mirrors` finds what was left.
            Log::error('signature-hub: could not delete mirror objects on revoke.', ['paths' => $paths, 'error' => $e->getMessage()]);

            return [];
        }

        return $paths;
    }

    /** @return array<int, string>  fingerprints revoked */
    private function revokeCertificates(int $userId): array
    {
        $certificates = UserCertificate::query()->where('user_id', $userId)->whereNull('revoked_at')->get();
        $service = app(CertificateService::class);

        foreach ($certificates as $certificate) {
            $service->revoke($certificate);
        }

        return $certificates->pluck('fingerprint')->all();
    }
}
