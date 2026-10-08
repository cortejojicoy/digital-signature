<?php

use Illuminate\Console\Events\CommandStarting;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Client\ClientServiceProvider;
use Kukux\DigitalSignature\Client\Exceptions\ClientMisconfiguredException;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Filament\Livewire\SignatureLauncher;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Signatories\HubUserMapper;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Support\SignatureMode;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * What client mode switches off and on: the hub owns signatures, devices and
 * certificates, so their surfaces are gone here, and the hub's sign-in,
 * webhook and links take their place.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

describe('client mode gating', function () {

    it('boots the client provider', function () {
        expect(SignatureMode::isClient())->toBeTrue()
            ->and(app()->getProviders(ClientServiceProvider::class))->not->toBeEmpty()
            ->and(config('signature.devices.enabled'))->toBeFalse();
    });

    it('registers no device or agent routes, but keeps the approval poll', function () {
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        expect($names->filter(fn ($n) => str_starts_with($n, 'signature.devices.')))->toBeEmpty()
            ->and($names->filter(fn ($n) => str_starts_with($n, 'signature.agent.') && ! str_starts_with($n, 'signature.agent.web.')))->toBeEmpty()
            ->and($names)->not->toContain('signature.device-fingerprint')
            ->and($names)->toContain('signature.agent.web.job')
            ->and($names)->toContain('signature.hub.login', 'signature.hub.callback', 'signature.hub.webhook');
    });

    it('leaves the Signatures resource off the panel', function () {
        $panel = Panel::make()->id('client-test')->path('client-test');
        SignaturePlugin::make()->register($panel);

        expect($panel->getResources())->not->toContain(SignatureResource::class);
    });

    it('binds the hub signatory mapper when the host has not bound its own', function () {
        expect(app(SignatoryUserMapper::class))->toBeInstanceOf(HubUserMapper::class);
    });

    it('renders a hub link instead of a signature pad', function () {
        expect(SignaturePad::make('signature')->getView())->toBe('signature::client.signature-pad');
    });

    it('shows the mirror read-only and links Library and Devices to the hub', function () {
        $user = $this->linkedUser();
        $this->mirror($user->id);
        $this->actingAs($user);

        $launcher = new SignatureLauncher;

        expect($launcher->getHubProperty())->toBe(['enabled' => true, 'profileUrl' => 'https://hub.test/'])
            ->and($launcher->getSignaturesProperty())->toHaveCount(1);

        Livewire::test(SignatureLauncher::class)
            ->call('loadRequests')
            ->assertSee('This is your signature from UPLB Signature.')
            ->assertSee('Change it at UPLB Signature')
            ->assertSee('Your signing computer is managed at UPLB Signature.')
            ->assertDontSee('Save signature')
            ->assertDontSee('class="dsig-panel__foot"', false);
    });

    it('hides signatures registered here before the move', function () {
        $user = $this->linkedUser();
        makePrimarySignature($user->id);
        $this->actingAs($user);

        expect((new SignatureLauncher)->getSignaturesProperty())->toHaveCount(0);
    });
});

describe('boot check', function () {

    it('refuses to serve HTTP without the hub settings', function () {
        config()->set('signature.hub.client_secret', null);
        config()->set('signature.hub.webhook_secret', '');

        expect(fn () => (new ClientServiceProvider(app()))->verifyConfiguration(console: false))
            ->toThrow(ClientMisconfiguredException::class, 'SIGNATURE_HUB_CLIENT_SECRET, SIGNATURE_HUB_WEBHOOK_SECRET are not set');
    });

    it('only warns in the console, so the installer can still run', function () {
        config()->set('signature.hub.url', null);

        (new ClientServiceProvider(app()))->verifyConfiguration(console: true);

        $output = new BufferedOutput;
        event(new CommandStarting('signature:install', new ArrayInput([]), $output));

        expect($output->fetch())->toContain('SIGNATURE_HUB_URL is not set');
    });

    it('is quiet when configured', function () {
        (new ClientServiceProvider(app()))->verifyConfiguration(console: false);

        expect(true)->toBeTrue();
    });
});
