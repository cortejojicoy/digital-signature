<?php

namespace Kukux\DigitalSignature\Console\Hub\Api;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Hub\Signing\HubSignRequestService;
use Kukux\DigitalSignature\Hub\Webhooks\HubWebhookDispatcher;
use Kukux\DigitalSignature\Models\HubWebhook;

/**
 * The hub's minute-by-minute sweep (schedule it everyMinute()):
 *
 *   1. expire sign requests whose agent job nobody claimed in time
 *      (their `sign_request.completed` goes into the outbox);
 *   2. deliver every outbox row whose next attempt is due (R6).
 *
 *   php artisan signature:hub-webhooks
 *   php artisan signature:hub-webhooks --redeliver=<event uuid>
 */
class HubWebhooksCommand extends Command
{
    protected $signature = 'signature:hub-webhooks
        {--redeliver= : Send one event again now, whatever its state}';

    protected $description = 'Deliver due hub webhooks and expire stale sign requests';

    public function handle(HubWebhookDispatcher $dispatcher, HubSignRequestService $requests): int
    {
        if ($uuid = $this->option('redeliver')) {
            $webhook = HubWebhook::query()->where('uuid', $uuid)->first();

            if ($webhook === null) {
                $this->error("No webhook with id {$uuid}.");

                return self::FAILURE;
            }

            $ok = $dispatcher->redeliver($webhook);
            $webhook->refresh();

            $ok
                ? $this->info("Delivered {$webhook->event} to {$webhook->app?->client_id}.")
                : $this->error("Failed: {$webhook->last_error}");

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $expired = $requests->expireDue();
        $delivered = $dispatcher->deliverDue();

        $this->info("Expired {$expired} sign request(s); delivered {$delivered} webhook(s).");

        return self::SUCCESS;
    }
}
