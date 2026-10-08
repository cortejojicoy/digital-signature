<?php

namespace Kukux\DigitalSignature\Hub;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Console\Hub\Api\HubAppCommand;
use Kukux\DigitalSignature\Console\Hub\Api\HubAuditMirrorsCommand;
use Kukux\DigitalSignature\Console\Hub\Api\HubWebhooksCommand;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar;
use Kukux\DigitalSignature\Hub\OAuth\HubOAuthServer;
use Kukux\DigitalSignature\Hub\Signing\CompleteHubSignRequest;
use Kukux\DigitalSignature\Hub\Signing\HubSignRequestService;
use Kukux\DigitalSignature\Hub\Signing\SignerCertificates;
use Kukux\DigitalSignature\Hub\Specimens\HubRevocation;
use Kukux\DigitalSignature\Hub\Specimens\MirrorAuditor;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Hub\Webhooks\HubWebhookDispatcher;

/**
 * Registered by SignatureServiceProvider only in the matching mode:
 * hub API, OAuth for apps, sign requests, webhooks outbox (docs/hub/api.md).
 */
class HubApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless services: one instance per process is enough.
        foreach ([
            People::class,
            HubOAuthServer::class,
            HubAppRegistrar::class,
            SignerCertificates::class,
            HubWebhookDispatcher::class,
            HubNotifier::class,
            SpecimenService::class,
            HubSignRequestService::class,
            HubRevocation::class,
            MirrorAuditor::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        $this->loadRoutesFrom(__DIR__.'/../../routes/hub-api.php');

        // An agent approved, declined or let expire a job: finish the hub
        // sign request it belongs to.
        Event::listen(AgentJobUpdated::class, CompleteHubSignRequest::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                HubAppCommand::class,
                HubWebhooksCommand::class,
                HubAuditMirrorsCommand::class,
            ]);
        }
    }

    /**
     *   signature-hub-token  30/min per client id + IP: secrets aren't guessable,
     *                        but nothing should get to try many
     *   signature-hub-api    600/min per token (per IP without one)
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('signature-hub-token', fn (Request $request) => Limit::perMinute(30)
            ->by('hub-token|'.$request->ip().'|'.(is_string($request->input('client_id')) ? $request->input('client_id') : (string) $request->getUser())));

        RateLimiter::for('signature-hub-api', fn (Request $request) => Limit::perMinute(600)
            ->by('hub-api|'.($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip())));
    }
}
