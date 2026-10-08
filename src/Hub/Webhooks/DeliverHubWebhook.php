<?php

namespace Kukux\DigitalSignature\Hub\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kukux\DigitalSignature\Models\HubWebhook;

/**
 * The first delivery attempt of one outbox row. One try only: retries are
 * the outbox's backoff (`signature:hub-webhooks`), not the queue's, so the
 * attempt count and next-attempt time stay in one place.
 */
class DeliverHubWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $webhookId) {}

    public function handle(HubWebhookDispatcher $dispatcher): void
    {
        $webhook = HubWebhook::query()->find($this->webhookId);

        if ($webhook !== null) {
            $dispatcher->deliver($webhook);
        }
    }
}
