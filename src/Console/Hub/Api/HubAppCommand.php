<?php

namespace Kukux\DigitalSignature\Console\Hub\Api;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar;

/**
 * Register an app with the hub and print its secrets, once.
 *
 *   php artisan signature:hub-app performance "UPLB Performance" \
 *       --redirect=https://performance.uplb.edu.ph/signature/hub/callback \
 *       --webhook=https://performance.uplb.edu.ph/signature/hub/webhook
 *
 * The client secret is stored only as a hash: lose it and rotate it from the
 * admin Apps page.
 */
class HubAppCommand extends Command
{
    protected $signature = 'signature:hub-app
        {client_id : Short id, e.g. performance (also the app\'s mirror prefix)}
        {name : What people see, e.g. "UPLB Performance"}
        {--redirect=* : An exact OAuth redirect URI (repeatable)}
        {--webhook= : Where the hub POSTs webhooks}
        {--scopes=* : signatures.read and/or sign (default: both)}';

    protected $description = 'Register an app with the signature hub and print its client and webhook secrets';

    public function handle(HubAppRegistrar $registrar): int
    {
        $scopes = $this->option('scopes') ?: HubAppRegistrar::SCOPES;

        try {
            $result = $registrar->create(
                (string) $this->argument('client_id'),
                (string) $this->argument('name'),
                (array) $this->option('redirect'),
                $this->option('webhook') ?: null,
                (array) $scopes,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $app = $result['app'];

        $this->info("Registered {$app->name} ({$app->client_id}).");
        $this->newLine();
        $this->line('Put these in the app\'s .env. They are shown only once.');
        $this->newLine();
        $this->line('SIGNATURE_MODE=client');
        $this->line('SIGNATURE_HUB_URL='.rtrim((string) config('app.url'), '/'));
        $this->line("SIGNATURE_HUB_CLIENT_ID={$app->client_id}");
        $this->line("SIGNATURE_HUB_CLIENT_SECRET={$result['client_secret']}");
        $this->line("SIGNATURE_HUB_WEBHOOK_SECRET={$result['webhook_secret']}");
        $this->newLine();
        $this->line('Scopes: '.implode(', ', (array) $app->scopes));
        $this->line('Redirect URIs: '.(implode(', ', (array) $app->redirect_uris) ?: 'none (the app can\'t sign people in until one is added)'));
        $this->line('Webhook: '.($app->webhook_url ?: 'none'));

        return self::SUCCESS;
    }
}
