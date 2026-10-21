<?php

namespace Kukux\DigitalSignature\Client;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Client\Console\HubRetryCommand;
use Kukux\DigitalSignature\Client\Console\HubSyncCommand;
use Kukux\DigitalSignature\Client\Console\HubSyncDocumentsCommand;
use Kukux\DigitalSignature\Client\Exceptions\ClientMisconfiguredException;
use Kukux\DigitalSignature\Client\Jobs\ReportDocumentToHub;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureDeclined;
use Kukux\DigitalSignature\Events\SigningSessionCompleted;
use Kukux\DigitalSignature\Events\SigningSessionOpened;
use Kukux\DigitalSignature\Signatories\HubUserMapper;
use Kukux\DigitalSignature\Signatories\IdentityUserMapper;
use Kukux\DigitalSignature\Support\SignatureMode;
use Throwable;

/**
 * Registered by SignatureServiceProvider only in the matching mode:
 * client mode: hub sign-in, mirrors, webhooks from the hub, hash-only signing (docs/hub/client.md).
 *
 * What it switches off, rather than adds: devices. The hub holds every
 * signing computer and the agent pairs with it alone, so
 * `signature.devices.enabled` is forced off here. That one switch stops the
 * device registry, agent approval and the presence check everywhere at once.
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HubClient::class);
        $this->app->singleton(HubSignatureSync::class);
        $this->app->singleton(HubSigning::class);
        $this->app->singleton(HubWebhookHandler::class);

        $this->app['config']->set('signature.devices.enabled', false);
    }

    public function boot(): void
    {
        $this->verifyConfiguration($this->app->runningInConsole());

        $this->loadRoutesFrom(__DIR__.'/../../routes/client.php');

        $this->bindSignatoryMapper();

        if ($this->app->runningInConsole()) {
            $this->commands([HubSyncCommand::class, HubRetryCommand::class, HubSyncDocumentsCommand::class]);
        }

        $this->registerLoginButton();

        $this->reportDocumentsToHub();
    }

    /**
     * Every change to a routed document queues a report of it to the hub, so
     * the hub can verify its PDFs (HubDocumentReporter). After the commit:
     * the job reads the session, and must see what was just written.
     */
    protected function reportDocumentsToHub(): void
    {
        if (! HubDocumentReporter::enabled()) {
            return;
        }

        Event::listen(
            [SigningSessionOpened::class, SigningSessionCompleted::class, DocumentSigned::class, SignatureDeclined::class],
            function (object $event): void {
                $sessionId = match (true) {
                    $event instanceof DocumentSigned    => $event->signature->signing_session_id,
                    $event instanceof SignatureDeclined => $event->request->signing_session_id,
                    default                             => $event->session->getKey(),
                };

                if ($sessionId !== null) {
                    ReportDocumentToHub::dispatch((int) $sessionId)->afterCommit();
                }
            },
        );
    }

    /**
     * A client app without its hub settings can't sign anyone in or sign
     * anything. Serving HTTP like that fails loudly; in the console it only
     * warns, so `signature:install --mode=client` and `config:cache` can still
     * run to put the settings in place.
     *
     * @throws ClientMisconfiguredException
     */
    public function verifyConfiguration(bool $console): void
    {
        $missing = SignatureMode::missingClientConfig();

        if ($missing === []) {
            return;
        }

        if (! $console) {
            throw ClientMisconfiguredException::missing($missing);
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($missing): void {
            $event->output->writeln(
                '<fg=yellow>  ! '.ClientMisconfiguredException::missing($missing)->getMessage().'</>'
            );
        });
    }

    /**
     * Tagged people are hub people here, unless the host bound its own
     * mapper (anything but the package default).
     */
    protected function bindSignatoryMapper(): void
    {
        try {
            $current = $this->app->make(SignatoryUserMapper::class);
        } catch (Throwable) {
            $current = null;
        }

        if ($current === null || $current::class === IdentityUserMapper::class) {
            $this->app->bind(SignatoryUserMapper::class, HubUserMapper::class);
        }
    }

    /** "Sign in with UPLB Signature" under every Filament login form. */
    protected function registerLoginButton(): void
    {
        if (! class_exists(FilamentView::class) || ! class_exists(PanelsRenderHook::class)) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
            fn () => view('signature::client.login-button', [
                'url' => route('signature.hub.login', array_filter(['panel' => Filament::getCurrentPanel()?->getId()])),
            ]),
        );
    }
}
