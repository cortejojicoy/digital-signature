<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar;
use Kukux\DigitalSignature\Hub\Specimens\HubRevocation;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Hub\Webhooks\HubWebhookDispatcher;
use Kukux\DigitalSignature\Models\HubWebhook;
use Kukux\DigitalSignature\Models\Signature;
use Throwable;

/**
 * The identity work's calls into the hub API services (docs/hub/contracts.md
 * §2.3): webhooks to holder apps, revocation, specimen publishing.
 *
 * Resolved from the container per call, so a test (or a hub app) can bind
 * its own. A notification that fails is reported, never thrown: the
 * identity change it follows has already been committed, and the webhook
 * outbox retries on its own. Revocation is not quiet: an admin who clicked
 * Revoke must hear when it didn't happen.
 */
class HubApiBridge
{
    public function signatureUpdated(string $personnelKey): void
    {
        $this->quietly(fn () => app(HubNotifier::class)->signatureUpdated($personnelKey));
    }

    public function personSeparated(string $personnelKey): void
    {
        $this->quietly(fn () => app(HubNotifier::class)->personSeparated($personnelKey));
    }

    /**
     * Marks the master signature revoked, revokes the certificate, deletes the
     * apps' mirrors, notifies holders and audits `signature.revoked`.
     *
     * @param  array{from: string, to: string}|null  $window
     */
    public function revokeSignature(int $userId, string $reason, ?int $actorId = null, ?array $window = null): void
    {
        app(HubRevocation::class)->revokeSignature($userId, $reason, $actorId, $window);
    }

    /** A new specimen was drawn on the Profile: copy it to the specimen disk and tell holders. */
    public function specimenPublished(Signature $signature): void
    {
        $this->quietly(fn () => app(SpecimenService::class)->published($signature));
    }

    public function redeliver(HubWebhook $webhook): bool
    {
        return app(HubWebhookDispatcher::class)->redeliver($webhook);
    }

    public function registrar(): HubAppRegistrar
    {
        return app(HubAppRegistrar::class);
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
