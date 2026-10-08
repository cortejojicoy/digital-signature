<?php

namespace Kukux\DigitalSignature\Hub\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\HubWebhook;

/**
 * Delivers the webhook outbox (docs/hub/contracts.md §3, R6).
 *
 *   POST <webhook_url>
 *   X-Signature-Hub-Id:        <event uuid>
 *   X-Signature-Hub-Event:     <event>
 *   X-Signature-Hub-Timestamp: <unix seconds>
 *   X-Signature-Hub-Signature: sha256=<hex hmac_sha256(webhook_secret, "<timestamp>.<raw body>")>
 *
 * Any 2xx is delivered. Otherwise the next attempt waits 1 minute, doubling
 * up to 24 hours, for at most `hub.webhook_max_attempts` tries; after that
 * the row stays in the outbox, failed, for the admin's Redeliver.
 */
class HubWebhookDispatcher
{
    public const BASE_DELAY = 60;

    public const MAX_DELAY = 86400;

    /** Queue one delivery (the outbox row already exists). */
    public function queue(HubWebhook $webhook): void
    {
        DeliverHubWebhook::dispatch($webhook->id)
            ->onQueue(config('signature.queue'))
            ->onConnection(config('signature.queue_connection'));
    }

    /**
     * One attempt, if the row is still due one. True when the app took it.
     */
    public function deliver(HubWebhook $webhook): bool
    {
        if ($webhook->delivered_at !== null || $webhook->attempts >= $this->maxAttempts()) {
            return $webhook->delivered_at !== null;
        }

        return $this->attempt($webhook);
    }

    /**
     * The admin's Redeliver: send again now, whatever the backoff or attempt
     * count, even if it was delivered before (apps dedupe on the event id).
     */
    public function redeliver(HubWebhook $webhook): bool
    {
        $webhook->forceFill(['delivered_at' => null])->save();

        return $this->attempt($webhook);
    }

    /**
     * Deliver every row whose next attempt is due. `signature:hub-webhooks`
     * runs this every minute.
     *
     * @return int  how many were delivered
     */
    public function deliverDue(int $limit = 500): int
    {
        $delivered = 0;

        HubWebhook::query()
            ->whereNull('delivered_at')
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->where('attempts', '<', $this->maxAttempts())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get()
            ->each(function (HubWebhook $webhook) use (&$delivered) {
                $delivered += (int) $this->deliver($webhook);
            });

        return $delivered;
    }

    /**
     * The exact bytes sent: stable across retries, so an app that logged a
     * failed attempt sees the same body again.
     */
    public function body(HubWebhook $webhook): string
    {
        return json_encode([
            'id'         => $webhook->uuid,
            'event'      => $webhook->event,
            'created_at' => $webhook->created_at?->toIso8601String(),
            'data'       => (object) ($webhook->payload ?? []),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    public function headers(HubWebhook $webhook, string $body, int $timestamp, string $secret): array
    {
        return [
            'Content-Type'              => 'application/json',
            'X-Signature-Hub-Id'        => $webhook->uuid,
            'X-Signature-Hub-Event'     => $webhook->event,
            'X-Signature-Hub-Timestamp' => (string) $timestamp,
            'X-Signature-Hub-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }

    /** Seconds to wait after the given number of failed attempts. */
    public static function backoff(int $attempts): int
    {
        return (int) min(self::MAX_DELAY, self::BASE_DELAY * (2 ** max(0, $attempts - 1)));
    }

    private function attempt(HubWebhook $webhook): bool
    {
        $app = $webhook->app;
        $attempts = $webhook->attempts + 1;

        if ($app === null || blank($app->webhook_url) || blank($app->webhook_secret)) {
            return $this->failed($webhook, $attempts, null, 'The app has no webhook URL or secret.');
        }

        $body = $this->body($webhook);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::withHeaders($this->headers($webhook, $body, $timestamp, (string) $app->webhook_secret))
                ->withBody($body, 'application/json')
                ->timeout((int) config('signature.hub.timeout', 10))
                ->withoutRedirecting()
                ->post($app->webhook_url);
        } catch (\Throwable $e) {
            return $this->failed($webhook, $attempts, null, Str::limit($e->getMessage(), 500));
        }

        if ($response->successful()) {
            $webhook->forceFill([
                'attempts'        => $attempts,
                'delivered_at'    => now(),
                'next_attempt_at' => null,
                'last_status'     => $response->status(),
                'last_error'      => null,
            ])->save();

            return true;
        }

        return $this->failed($webhook, $attempts, $response->status(), Str::limit(trim($response->body()), 500) ?: "HTTP {$response->status()}");
    }

    private function failed(HubWebhook $webhook, int $attempts, ?int $status, string $error): bool
    {
        $webhook->forceFill([
            'attempts'        => $attempts,
            'last_status'     => $status,
            'last_error'      => $error,
            'next_attempt_at' => $attempts >= $this->maxAttempts() ? null : now()->addSeconds(self::backoff($attempts)),
        ])->save();

        return false;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('signature.hub.webhook_max_attempts', 12));
    }
}
