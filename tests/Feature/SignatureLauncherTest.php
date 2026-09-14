<?php

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Filament\Livewire\SignatureLauncher;
use Kukux\DigitalSignature\Filament\Pages\SignatureInbox;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Support\LauncherSettings;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;
use Kukux\DigitalSignature\Tests\Support\TestUser;
use Livewire\Livewire;

/**
 * The floating launcher. Two things are worth testing beyond "it renders":
 * that its queue is the same queue the full-page inbox shows (they share
 * ActsOnSignatureRequests, and a divergence there would let a signatory act on
 * a request the inbox says is not theirs), and that navigation suppression is
 * conditional on the launcher actually being present — a panel with neither a
 * launcher nor a sidebar item would be unreachable.
 */
function launcherSession(): AccomplishmentReport
{
    arSessionTemplate();

    makeUser(11, 'Juan Dela Cruz');
    makeUser(12, 'Maria Santos');
    makeUser(13, 'Dr Reyes');

    makePrimarySignature(11);
    makePrimarySignature(12);
    makePrimarySignature(13);

    $report = AccomplishmentReport::create([
        'prepared_by_id' => 11,
        'attested_by_id' => 12,
        'noted_by_id'    => 13,
    ]);

    app(SigningSessionManager::class)->open($report);

    return $report;
}

describe('floating launcher component', function () {

    beforeEach(function () {
        Storage::fake('testing');
    });

    it('counts only the documents waiting on the signed-in user', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        expect((new SignatureLauncher)->getCountProperty())->toBe(1);

        $this->actingAs(makeUser(99, 'Uninvolved Person'));

        expect((new SignatureLauncher)->getCountProperty())->toBe(0);
    });

    it('shows nothing to a guest', function () {
        launcherSession();

        $component = new SignatureLauncher;

        expect($component->getCountProperty())->toBe(0)
            ->and($component->getRequestsProperty())->toHaveCount(0)
            ->and($component->getSignaturesProperty())->toHaveCount(0);
    });

    it('lists the signer library so an unsigned-up user is told why they cannot sign', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));
        expect((new SignatureLauncher)->getSignaturesProperty())->toHaveCount(1);

        // Tagged on the document, but never registered a signature.
        $this->actingAs(makeUser(14, 'Unprepared Person'));
        expect((new SignatureLauncher)->getSignaturesProperty())->toHaveCount(0);
    });

    it('renders the button with a badge and defers the queue until opened', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $component = Livewire::test(SignatureLauncher::class);

        $component->assertSee('dsig-fab', escape: false)
            ->assertSee('awaiting your signature')
            ->assertSet('loaded', false)
            // The skeleton stands in for the list until the drawer is opened.
            ->assertSee('dsig-skeleton', escape: false)
            ->assertDontSee('Decline');

        $component->call('loadRequests')
            ->assertSet('loaded', true)
            ->assertSee('Decline');
    });

    it('declines a request through the same ownership checks as the inbox', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $mine = SignatureRequest::where('user_id', 11)->firstOrFail();
        $theirs = SignatureRequest::where('user_id', 12)->firstOrFail();

        Livewire::test(SignatureLauncher::class)->call('declineRequest', $theirs->id);

        expect($theirs->refresh()->state)->not->toBe(RouteState::Declined);

        Livewire::test(SignatureLauncher::class)->call('declineRequest', $mine->id);

        expect($mine->refresh()->state)->toBe(RouteState::Declined);
    });

    it('offers the document rather than a blind Sign button', function () {
        // The button this replaced signed a PDF the signatory had never seen.
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $html = Livewire::test(SignatureLauncher::class)->call('loadRequests')->html();

        expect($html)->toContain('View &amp; sign')
            ->and($html)->not->toContain('wire:click="signRequest');
    });

    it('carries the library and the queue in one drawer', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $html = Livewire::test(SignatureLauncher::class)->call('loadRequests')->html();

        expect($html)->toContain('My signatures')
            ->and($html)->toContain("tab === 'library'")
            // This user already has one, so the library shows it rather than
            // the capture pad — the single-primary rule the resource page
            // applies, applied here.
            ->and($html)->toContain('dsig-lib__item')
            ->and($html)->toContain('You already have an active signature');
    });

    it('offers the capture pad in the drawer to a user who has no signature yet', function () {
        Storage::fake('testing');

        $this->actingAs(makeUser(23, 'Unsigned Person'));

        $html = Livewire::test(SignatureLauncher::class)->call('loadRequests')->html();

        // The same React island the Filament field mounts, not a second
        // capture implementation.
        expect($html)->toContain('data-signature-canvas')
            ->and($html)->toContain('dsig-launcher-pad');
    });

    it('hands the drawer the URL templates and asset URLs its viewer needs', function () {
        $this->actingAs(makeUser(99, 'Uninvolved Person'));

        $viewer = (new SignatureLauncher)->getViewerProperty();

        // Templates, not URLs: the pane opens without a round trip, so the
        // request id is only known in the browser.
        expect($viewer['metaUrlTemplate'])->toContain('__ID__')
            ->and($viewer['signUrlTemplate'])->toContain('__ID__')
            // The worker must be served by this app. A CDN would leave an
            // offline panel rendering nothing at all.
            ->and($viewer['workerSrc'])->not->toStartWith('//')
            ->and($viewer['workerSrc'])->not->toContain('cdn');
    });

    it('registers a signature from the drawer under the same rules as the resource page', function () {
        Storage::fake('testing');

        $this->actingAs(makeUser(21, 'New Signer'));

        Livewire::test(SignatureLauncher::class)
            ->set('newSignature', fakePng())
            ->set('newCertificatePassword', 'hunter2')
            ->call('createSignature');

        expect(\Kukux\DigitalSignature\Models\Signature::where('user_id', 21)->count())->toBe(1);

        // A second one is refused by the single-primary rule, wherever it is
        // attempted from.
        Livewire::test(SignatureLauncher::class)
            ->set('newSignature', fakePng())
            ->set('newCertificatePassword', 'hunter2')
            ->call('createSignature');

        expect(\Kukux\DigitalSignature\Models\Signature::where('user_id', 21)->count())->toBe(1);
    });

    it('never keeps the certificate password in component state', function () {
        Storage::fake('testing');

        $this->actingAs(makeUser(22, 'Careful Signer'));

        Livewire::test(SignatureLauncher::class)
            ->set('newSignature', fakePng())
            ->set('newCertificatePassword', 'hunter2')
            ->call('createSignature')
            ->assertSet('newCertificatePassword', null)
            ->assertSet('newSignature', null);
    });

    it('hides the button entirely when configured to and nothing is waiting', function () {
        config()->set('signature.launcher.hide_when_empty', true);

        $this->actingAs(makeUser(99, 'Uninvolved Person'));

        Livewire::test(SignatureLauncher::class)->assertDontSee('dsig-fab"', escape: false);
    });
});

describe('launcher placement', function () {

    beforeEach(function () {
        Storage::fake('testing');
        $this->actingAs(makeUser(99, 'Uninvolved Person'));
    });

    it('carries its corner offsets and layer into the markup as custom properties', function () {
        config()->set('signature.launcher.offset', ['x' => '2rem', 'y' => '3rem']);
        config()->set('signature.launcher.z_index', 60);

        $html = Livewire::test(SignatureLauncher::class)->html();

        expect($html)->toContain('--dsig-x: 2rem')
            ->and($html)->toContain('--dsig-y: 3rem')
            ->and($html)->toContain('--dsig-z: 60');
    });

    it('carries the configured drawer width into the markup', function () {
        config()->set('signature.launcher.width', '48rem');

        expect(LauncherSettings::width())->toBe('48rem')
            ->and(Livewire::test(SignatureLauncher::class)->html())->toContain('--dsig-w: 48rem');
    });

    it('defaults the drawer wide enough to read a document in', function () {
        // The drawer is no longer a notification rail — it holds the PDF the
        // signatory is being asked to sign.
        expect(LauncherSettings::width())->toBe('64rem');
    });

    it('refuses a drawer width that is not a plain CSS length', function () {
        config()->set('signature.launcher.width', '80rem; position: static');

        expect(LauncherSettings::width())->toBe('64rem');
    });

    it('refuses an offset that is not a plain CSS length', function () {
        // Offsets land in a style attribute; a config value is not a place to
        // accept arbitrary declarations.
        config()->set('signature.launcher.offset', ['x' => 'red; position: static', 'y' => '4px']);

        expect(LauncherSettings::offsetX())->toBe('1.5rem')
            ->and(LauncherSettings::offsetY())->toBe('4px');
    });

    it('hands the browser what it needs to stack clear of the host app', function () {
        config()->set('signature.launcher.gap', 20);
        config()->set('signature.launcher.avoid', ['#intercom-launcher', '  ', '.crisp-client']);
        config()->set('signature.launcher.ignore', ['.toast-rail']);

        $placement = (new SignatureLauncher)->getPlacementProperty();

        expect($placement['enabled'])->toBeTrue()
            ->and($placement['position'])->toBe('bottom-right')
            ->and($placement['gap'])->toBe(20)
            // Blank entries would become querySelectorAll('') — a DOM exception
            // on every placement pass.
            ->and($placement['avoid'])->toBe(['#intercom-launcher', '.crisp-client'])
            ->and($placement['ignore'])->toBe(['.toast-rail']);
    });

    it('can be told not to probe at all', function () {
        config()->set('signature.launcher.avoid_overlap', false);

        expect((new SignatureLauncher)->getPlacementProperty()['enabled'])->toBeFalse();

        // The button still renders; it just stays exactly where config put it.
        Livewire::test(SignatureLauncher::class)->assertSee('dsig-fab', escape: false);
    });

    it('falls back to a known corner when given a position it does not have', function () {
        config()->set('signature.launcher.position', 'middle-of-the-screen');

        expect(LauncherSettings::position())->toBe('bottom-right');
    });
});

describe('launcher navigation suppression', function () {

    it('takes over navigation from the inbox page and the resource by default', function () {
        expect(LauncherSettings::replacesNavigation())->toBeTrue()
            ->and(SignatureInbox::shouldRegisterNavigation())->toBeFalse()
            ->and(SignatureResource::shouldRegisterNavigation())->toBeFalse();
    });

    it('leaves navigation alone when the launcher is off, so the panel is never unreachable', function () {
        config()->set('signature.launcher.enabled', false);

        expect(LauncherSettings::replacesNavigation())->toBeFalse()
            ->and(SignatureInbox::shouldRegisterNavigation())->toBeTrue()
            ->and(SignatureResource::shouldRegisterNavigation())->toBeTrue();
    });

    it('can show both the launcher and the sidebar items', function () {
        config()->set('signature.launcher.replaces_navigation', false);

        expect(LauncherSettings::enabled())->toBeTrue()
            ->and(SignatureInbox::shouldRegisterNavigation())->toBeTrue()
            ->and(SignatureResource::shouldRegisterNavigation())->toBeTrue();
    });

    it('still honours the inbox navigation flag when the launcher is off', function () {
        config()->set('signature.launcher.enabled', false);
        config()->set('signature.inbox.navigation', false);

        expect(SignatureInbox::shouldRegisterNavigation())->toBeFalse();
    });

    it('lets a panel opt out of the launcher without touching config', function () {
        $plugin = SignaturePlugin::make();

        expect($plugin->hasLauncherOverride())->toBeFalse()
            ->and($plugin->wantsLauncher())->toBeTrue();

        $plugin->withoutFloatingLauncher();

        expect($plugin->hasLauncherOverride())->toBeTrue()
            ->and($plugin->wantsLauncher())->toBeFalse();
    });
});
