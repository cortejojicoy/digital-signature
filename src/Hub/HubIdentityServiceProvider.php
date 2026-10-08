<?php

namespace Kukux\DigitalSignature\Hub;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Console\Hub\Identity\HubAdminCommand;
use Kukux\DigitalSignature\Console\Hub\Identity\PruneProvisionalCommand;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Hub\Identity\EloquentPersonnelDirectory;
use Kukux\DigitalSignature\Hub\Identity\Http\Responses\HubLoginResponse;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\Listeners\HandleIdentityAgentJobs;

/**
 * Registered by SignatureServiceProvider only in the matching mode:
 * pair-first onboarding, agent sign-in, identities, transfers and the hub panels (docs/hub/identity.md).
 */
class HubIdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Who works here. The hub app binds its own when its registry isn't
        // a single Eloquent model; bindIf so a provider registered first wins.
        $this->app->bindIf(PersonnelDirectory::class, EloquentPersonnelDirectory::class);

        $this->app->singleton(IdentityService::class);

        // Every Filament sign-in lands where HubRedirector says (plan 1.8).
        // Filament 4/5 only: the hub panels need ->topbar(false).
        if (interface_exists(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class)) {
            $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, HubLoginResponse::class);
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/hub-web.php');

        // Agent answers to `login` and `transfer` jobs (docs/hub/contracts.md §4).
        Event::listen(AgentJobUpdated::class, HandleIdentityAgentJobs::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneProvisionalCommand::class, HubAdminCommand::class]);

            // Abandoned "Pair this computer" accounts (R10).
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('signature:prune-provisional')->hourly()->withoutOverlapping();
            });
        }
    }
}
