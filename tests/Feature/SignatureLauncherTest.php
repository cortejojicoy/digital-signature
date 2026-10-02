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

    it('keeps a signed document as a dated record instead of dropping it', function () {
        // The complaint this answers: signing made the document vanish, with
        // no way to see what you had put your certificate on.
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $request = SignatureRequest::where('user_id', 11)->firstOrFail();
        $request->update(['state' => RouteState::Signed, 'responded_at' => now()]);

        $component = new SignatureLauncher;
        $history = $component->getSignedHistoryProperty();

        expect($history)->toHaveCount(1)
            ->and($history[0]['label'])->toBe('Today')
            ->and($history[0]['requests'])->toHaveCount(1)
            // ...and it is out of the queue, which is still only what is
            // waiting on them.
            ->and($component->getRequestsProperty())->toHaveCount(0);
    });

    it('groups signed documents by the day they were signed', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        $request = SignatureRequest::where('user_id', 11)->firstOrFail();
        $request->update(['state' => RouteState::Signed, 'responded_at' => now()->subDay()]);

        $history = (new SignatureLauncher)->getSignedHistoryProperty();

        expect($history)->toHaveCount(1)
            ->and($history[0]['label'])->toBe('Yesterday');
    });

    it('shows a guest no history at all', function () {
        launcherSession();

        SignatureRequest::query()->update(['state' => RouteState::Signed, 'responded_at' => now()]);

        expect((new SignatureLauncher)->getSignedHistoryProperty())->toBe([]);
    });

    it('never shows one signatory another signatory’s history', function () {
        launcherSession();

        SignatureRequest::query()->update(['state' => RouteState::Signed, 'responded_at' => now()]);

        $this->actingAs(TestUser::findOrFail(11));
        $mine = (new SignatureLauncher)->getSignedHistoryProperty();

        expect($mine[0]['requests'])->toHaveCount(1)
            ->and($mine[0]['requests']->first()->user_id)->toBe(11);
    });

    it('renders the signed tab with its day headings', function () {
        launcherSession();

        $this->actingAs(TestUser::findOrFail(11));

        SignatureRequest::where('user_id', 11)
            ->update(['state' => RouteState::Signed, 'responded_at' => now()]);

        $html = Livewire::test(SignatureLauncher::class)->call('loadRequests')->html();

        expect($html)->toContain("tab === 'signed'")
            ->and($html)->toContain('dsig-daygroup')
            ->and($html)->toContain('Today')
            ->and($html)->toContain('View document');
    });

    it('hides the button entirely when configured to and nothing is waiting', function () {
        config()->set('signature.launcher.hide_when_empty', true);

        $this->actingAs(makeUser(99, 'Uninvolved Person'));

        Livewire::test(SignatureLauncher::class)->assertDontSee('dsig-fab"', escape: false);
    });
});

describe('managing signatures in the drawer', function () {

    beforeEach(function () {
        Storage::fake('testing');
    });

    it('opens manage mode in the drawer instead of linking to the resource', function () {
        $this->actingAs(makeUser(31, 'Footer Reader'));

        $html = Livewire::test(SignatureLauncher::class)->html();

        expect($html)->toContain('x-on:click="manage()"')
            ->and($html)->toContain('Manage signatures')
            ->and($html)->not->toContain('href="'.url('/admin/signatures').'"');
    });

    it('defers the list until manage mode is first opened', function () {
        $this->actingAs(makeUser(32, 'Lazy Loader'));
        makePrimarySignature(32);

        expect((new SignatureLauncher)->getManagedSignaturesProperty())->toHaveCount(0);

        Livewire::test(SignatureLauncher::class)
            ->assertSet('manageLoaded', false)
            ->call('manage')
            ->assertSet('manageLoaded', true)
            ->assertSet('loaded', true)
            ->assertSee('Your signature');
    });

    it('only ever lists and selects the signed-in user’s own signatures', function () {
        makeUser(33, 'Owner');
        makeUser(34, 'Stranger');
        $mine = makePrimarySignature(33);
        $theirs = makePrimarySignature(34);

        $this->actingAs(TestUser::findOrFail(33));

        $component = Livewire::test(SignatureLauncher::class)->call('manage', $mine->uuid);

        $component->assertSet('managing', $mine->uuid);
        expect($component->instance()->getManagedSignaturesProperty()->pluck('id')->all())->toBe([$mine->id]);

        // Someone else's uuid, sent straight from the browser, selects nothing.
        $component->call('manage', $theirs->uuid)
            ->assertSet('managing', null)
            ->assertDontSee($theirs->image_hash);
    });

    it('revokes the user’s own signature and refuses anyone else’s', function () {
        makeUser(35, 'Revoker');
        makeUser(36, 'Bystander');
        $mine = makePrimarySignature(35);
        $theirs = makePrimarySignature(36);

        $this->actingAs(TestUser::findOrFail(35));

        Livewire::test(SignatureLauncher::class)->call('revokeSignature', $theirs->uuid);
        expect($theirs->refresh()->isRevoked())->toBeFalse();

        Livewire::test(SignatureLauncher::class)->call('revokeSignature', $mine->uuid);
        expect($mine->refresh()->isRevoked())->toBeTrue();

        // Revoking again changes nothing, including when it was revoked.
        $revokedAt = $mine->revoked_at;
        $this->travel(5)->minutes();

        Livewire::test(SignatureLauncher::class)->call('revokeSignature', $mine->uuid);
        expect($mine->refresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
    });

    it('lets the user register a new signature once the old one is revoked', function () {
        $this->actingAs(makeUser(37, 'Fresh Start'));
        $signature = makePrimarySignature(37);

        $component = Livewire::test(SignatureLauncher::class)->call('manage', $signature->uuid);
        expect($component->html())->toContain('You already have an active signature');

        $component->call('revokeSignature', $signature->uuid);
        expect($component->html())->toContain('dsig-launcher-pad');
    });

    it('shows everything the View page did for the selected signature', function () {
        $this->actingAs(makeUser(38, 'Detail Reader'));
        $signature = makePrimarySignature(38);

        $html = Livewire::test(SignatureLauncher::class)->call('manage', $signature->uuid)->html();

        expect($html)->toContain('Download image')
            ->and($html)->toContain('Revoke signature')
            ->and($html)->toContain('Created on')
            ->and($html)->toContain('Used on')
            ->and($html)->toContain('Apply this signature')
            ->and($html)->toContain('Security metadata')
            ->and($html)->toContain($signature->image_hash);
    });

    it('keeps Download but drops Revoke for a revoked signature', function () {
        $this->actingAs(makeUser(39, 'Former Signer'));
        $signature = makePrimarySignature(39);
        $signature->update(['status' => 'revoked', 'revoked_at' => now()]);

        $html = Livewire::test(SignatureLauncher::class)->call('manage', $signature->uuid)->html();

        expect($html)->toContain('Download image')
            ->and($html)->not->toContain('Revoke signature');
    });

    it('reports how far each template’s placement is set up', function () {
        arSessionTemplate();
        registerRoutedTemplate('half-done', [
            'first'  => ['label' => 'First', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
            'second' => ['label' => 'Second', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
        ], ['renderer' => \Kukux\DigitalSignature\Tests\Support\StubPdfRenderer::class]);

        \Kukux\DigitalSignature\Models\PdfTemplateSlot::create([
            'template_key' => 'half-done', 'slot_key' => 'first',
            'page' => 1, 'x' => 10.0, 'y' => 10.0, 'width' => 100.0, 'height' => 40.0,
        ]);

        $this->actingAs(makeUser(40, 'Template User'));
        $signature = makePrimarySignature(40);

        $cards = collect((new SignatureLauncher)->templateCardsFor($signature))->keyBy('key');

        expect($cards['accomplishment-report'])->toMatchArray(['slotCount' => 3, 'savedCount' => 3, 'configured' => true])
            ->and($cards['half-done'])->toMatchArray(['slotCount' => 2, 'savedCount' => 1, 'configured' => false]);
    });

    it('opens a template in the drawer instead of linking to the signer or designer', function () {
        arSessionTemplate();

        $this->actingAs(makeUser(42, 'Previewer'));
        $card = (new SignatureLauncher)->templateCardsFor(makePrimarySignature(42))[0];

        expect($card['previewMetaUrl'])->toBe(route('signature.pdf-templates.preview.meta', ['template' => 'accomplishment-report']))
            ->and($card)->not->toHaveKey('signerUrl')
            ->and($card)->not->toHaveKey('designerUrl');
    });

    it('serves a template preview read-only, to signed-in users only', function () {
        Storage::fake('testing');
        arSessionTemplate();

        $this->getJson(route('signature.pdf-templates.preview.meta', ['template' => 'accomplishment-report']))
            ->assertForbidden();

        $this->actingAs(makeUser(43, 'Viewer'));

        $this->getJson(route('signature.pdf-templates.preview.meta', ['template' => 'accomplishment-report']))
            ->assertOk()
            ->assertJson([
                'readOnly' => true,
                'requests' => [],
                'signatures' => [],
                'back' => 'Manage signatures',
                'document' => ['url' => route('signature.pdf-templates.preview.document', ['template' => 'accomplishment-report'])],
            ]);

        $this->get(route('signature.pdf-templates.preview.document', ['template' => 'accomplishment-report']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->getJson(route('signature.pdf-templates.preview.meta', ['template' => 'nope']))->assertNotFound();
    });

    it('offers no templates for a signature already used on a document', function () {
        arSessionTemplate();

        $this->actingAs(makeUser(41, 'Document Signer'));
        $use = makePrimarySignature(41);
        $use->forceFill(['signable_type' => 'report', 'signable_id' => 1, 'status' => 'signed'])->save();

        expect((new SignatureLauncher)->templateCardsFor($use))->toBe([]);
    });

    it('turns library thumbnails into a way into manage mode', function () {
        $this->actingAs(makeUser(42, 'Thumbnail Clicker'));
        $signature = makePrimarySignature(42);

        $html = Livewire::test(SignatureLauncher::class)->call('loadRequests')->html();

        expect($html)->toContain("x-on:click=\"manage('{$signature->uuid}')\"");
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

    it('widens further while managing signatures', function () {
        config()->set('signature.launcher.manage_width', '90rem');

        expect(LauncherSettings::manageWidth())->toBe('90rem')
            ->and(Livewire::test(SignatureLauncher::class)->html())->toContain('--dsig-w-manage: 90rem');
    });

    it('defaults the manage width and refuses one that is not a plain CSS length', function () {
        expect(LauncherSettings::manageWidth())->toBe('80rem');

        config()->set('signature.launcher.manage_width', '90rem; display: none');

        expect(LauncherSettings::manageWidth())->toBe('80rem');
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
