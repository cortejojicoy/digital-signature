<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Kukux\DigitalSignature\Hub\Identity\HubApiBridge;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubWebhook;

/**
 * Admin: the apps that use the hub (plan 1.7, A11): OAuth clients, their
 * webhook URL, and the webhook outbox with Redeliver (R6).
 *
 * Registering and rotating go through the hub API's HubAppRegistrar. A new
 * secret is shown exactly once, here, right after it is made; only its hash
 * (client secret) or its encrypted form (webhook secret) is kept.
 */
class Apps extends Page
{
    protected string $view = 'signature::hub.pages.apps';

    protected static ?string $slug = 'apps';

    protected static ?int $navigationSort = 4;

    public string $clientId = '';

    public string $name = '';

    /** One redirect URI per line. */
    public string $redirectUris = '';

    public string $webhookUrl = '';

    /** Shown once after create / rotate, then gone. */
    public ?array $secrets = null;

    public ?int $selectedApp = null;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-squares-2x2';
    }

    public static function getNavigationLabel(): string
    {
        return 'Apps';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Apps';
    }

    public function create(HubApiBridge $api): void
    {
        $uris = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->redirectUris) ?: [])));

        try {
            $created = $api->registrar()->create(trim($this->clientId), trim($this->name), $uris, trim($this->webhookUrl) ?: null);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->secrets = [
            'app'            => $created['app']->client_id,
            'client_secret'  => $created['client_secret'],
            'webhook_secret' => $created['webhook_secret'],
        ];
        $this->reset('clientId', 'name', 'redirectUris', 'webhookUrl');
    }

    public function rotateSecret(int $appId, HubApiBridge $api): void
    {
        $app = HubApp::query()->findOrFail($appId);

        $this->secrets = ['app' => $app->client_id, 'client_secret' => $api->registrar()->rotateSecret($app)];
    }

    public function rotateWebhookSecret(int $appId, HubApiBridge $api): void
    {
        $app = HubApp::query()->findOrFail($appId);

        $this->secrets = ['app' => $app->client_id, 'webhook_secret' => $api->registrar()->rotateWebhookSecret($app)];
    }

    public function toggleActive(int $appId): void
    {
        $app = HubApp::query()->findOrFail($appId);
        $app->update(['active' => ! $app->active]);
    }

    public function showDeliveries(int $appId): void
    {
        $this->selectedApp = $this->selectedApp === $appId ? null : $appId;
    }

    public function redeliver(int $webhookId, HubApiBridge $api): void
    {
        $webhook = HubWebhook::query()->findOrFail($webhookId);

        $api->redeliver($webhook)
            ? Notification::make()->title('Delivered.')->success()->send()
            : Notification::make()->title('Delivery failed: '.($webhook->fresh()->last_error ?: 'HTTP '.$webhook->fresh()->last_status))->danger()->send();
    }

    public function dismissSecrets(): void
    {
        $this->secrets = null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'apps' => HubApp::query()
                ->withCount([
                    'holders',
                    'webhooks as failing_count' => fn ($q) => $q->whereNull('delivered_at')->where('attempts', '>', 0),
                ])
                ->orderBy('name')
                ->get(),
            'deliveries' => $this->selectedApp === null ? collect() : HubWebhook::query()
                ->where('app_id', $this->selectedApp)
                ->latest('id')
                ->limit(25)
                ->get(),
        ];
    }
}
