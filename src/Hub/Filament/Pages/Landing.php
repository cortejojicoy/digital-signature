<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Enums\DeviceType;
use Kukux\DigitalSignature\Hub\Identity\BreakGlass;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;

/**
 * The person panel's login page, which is the hub's front door (plan 1.1,
 * 1.5): "Pair this computer", "Sign in with your computer" and the agent
 * downloads. No email, no password.
 *
 * Both flows are the page's own script against GuestPairingController and
 * HubLoginController, polling while the agent does its part, so nothing here
 * holds state between requests: the browser binding lives in the session.
 */
class Landing extends SimplePage
{
    protected string $view = 'signature::hub.pages.landing';

    public function mount(): void
    {
        if (auth()->check()) {
            $this->redirect(app(HubRedirector::class)->afterSignIn(auth()->user()));
        }
    }

    public function getTitle(): string|Htmlable
    {
        return config('app.name', 'Signature hub');
    }

    public function getHeading(): string|Htmlable|null
    {
        return config('app.name', 'Signature hub');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Your signature, kept on your own computer.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'agentEnabled' => AgentServer::enabled(),
            'downloads'    => array_filter((array) config('signature.hub.downloads', [])),
            'deviceTypes'  => DeviceType::grouped(),
            'vmBlocked'    => AgentPairingService::blocks(DeviceType::VirtualMachine, true),
            'breakGlass'   => app(BreakGlass::class)->allows(request()->ip()),
            'endpoints'    => [
                'pair'  => route('signature.hub.pairings.store'),
                'login' => route('signature.hub.login.store'),
            ],
        ];
    }
}
