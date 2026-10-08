<?php

namespace Kukux\DigitalSignature\Hub\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\HubSignRequest;
use Kukux\DigitalSignature\Models\HubWebhook;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Tells apps what changed (docs/hub/contracts.md §3). Each method writes one
 * outbox row per recipient first, then hands the rows to the dispatcher, so
 * a crash or an app outage loses nothing (R6).
 *
 * Recipients: the active apps that hold a mirror of that person
 * (HubHolder) and have a webhook URL, except `sign_request.completed`, which
 * goes only to the app that asked.
 */
class HubNotifier
{
    public function __construct(
        private readonly People $people,
        private readonly HubWebhookDispatcher $dispatcher,
    ) {}

    public function signatureUpdated(string $personnelKey): void
    {
        $signature = $this->latestSignature($personnelKey, active: true);

        if ($signature === null) {
            return;
        }

        $this->toHolders($personnelKey, 'signature.updated', [
            'sub'          => $personnelKey,
            'uuid'         => $signature->uuid,
            'image_sha256' => $signature->image_hash,
        ]);
    }

    /**
     * @param  array{from: string, to: string}|null  $window  Signatures made in this window are suspect (R2, R8).
     */
    public function signatureRevoked(string $personnelKey, string $reason, ?array $window = null): void
    {
        $signature = $this->latestSignature($personnelKey, active: false);

        $this->toHolders($personnelKey, 'signature.revoked', array_filter([
            'sub'    => $personnelKey,
            'uuid'   => $signature?->uuid,
            'reason' => $reason,
            'window' => $window,
        ], fn ($v) => $v !== null));
    }

    public function personSeparated(string $personnelKey): void
    {
        $this->toHolders($personnelKey, 'person.separated', ['sub' => $personnelKey]);
    }

    /**
     * Signatures made in a compromise window (R2 runbook, step 5).
     *
     * @param  array<int, string>  $documentHashes
     * @param  array{from: string, to: string}  $window
     */
    public function signatureFlagged(string $personnelKey, array $documentHashes, array $window): void
    {
        $this->toHolders($personnelKey, 'signature.flagged', [
            'sub'             => $personnelKey,
            'document_hashes' => array_values($documentHashes),
            'window'          => $window,
        ]);
    }

    public function signRequestCompleted(HubSignRequest $request): void
    {
        $app = $request->app;

        if (! $this->receives($app)) {
            return;
        }

        $data = [
            'id'     => $request->uuid,
            'sub'    => $request->personnel_key,
            'status' => $request->status,
        ];

        if ($request->status === 'signed') {
            $data['cms'] = $request->cms;
        }

        if ($request->refusal_reason !== null && $request->status !== 'signed') {
            $data['refusal_reason'] = $request->refusal_reason;
        }

        $this->dispatch([$this->write($app, 'sign_request.completed', $data)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toHolders(string $personnelKey, string $event, array $data): void
    {
        $apps = HubApp::query()
            ->whereIn('id', HubHolder::query()->where('personnel_key', $personnelKey)->select('app_id'))
            ->get()
            ->filter(fn (HubApp $app) => $this->receives($app));

        $this->dispatch($apps->map(fn (HubApp $app) => $this->write($app, $event, $data))->all());
    }

    private function receives(?HubApp $app): bool
    {
        return $app !== null && $app->active && filled($app->webhook_url);
    }

    /** @param  array<string, mixed>  $data */
    private function write(HubApp $app, string $event, array $data): HubWebhook
    {
        return HubWebhook::create([
            'uuid'            => (string) Str::uuid(),
            'app_id'          => $app->id,
            'event'           => $event,
            'payload'         => $data,
            'attempts'        => 0,
            // The queued job tries at once; this is when the scheduled
            // sweep picks it up if that job never runs.
            'next_attempt_at' => now()->addSeconds(HubWebhookDispatcher::BASE_DELAY),
        ]);
    }

    /**
     * Queue delivery once the surrounding transaction (if any) commits, so a
     * worker never picks up a row that was rolled back.
     *
     * @param  array<int, HubWebhook>  $webhooks
     */
    private function dispatch(array $webhooks): void
    {
        if ($webhooks === []) {
            return;
        }

        DB::afterCommit(function () use ($webhooks) {
            foreach ($webhooks as $webhook) {
                $this->dispatcher->queue($webhook);
            }
        });
    }

    /**
     * The person's newest primary signature across all their accounts:
     * the active one, or (for a revocation) the one just revoked.
     */
    private function latestSignature(string $personnelKey, bool $active): ?Signature
    {
        $userIds = $this->people->allUserIdsFor($personnelKey);

        if ($userIds === []) {
            return null;
        }

        return Signature::query()
            ->whereIn('user_id', $userIds)
            ->primary()
            ->where('source', '!=', 'hub')
            ->where('status', $active ? 'active' : 'revoked')
            ->latest($active ? 'id' : 'revoked_at')
            ->latest('id')
            ->first();
    }
}
