<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\ListSignatures;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\OpenSignatureInDrawer;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Support\FilamentVersion;
use Livewire\Livewire;

/**
 * A real panel with the plugin on it and Filament's own routes, so resource
 * pages render the way they do in a host app. Registered per test: the panel
 * routes file reads the panel list when it is loaded.
 */
function signaturePanel(): Panel
{
    // The rest of the suite boots only Filament's core. A resource page also
    // needs the packages its page renders through; which exist depends on the
    // major (Schemas is v4+; v3's form views need the @capture directive), so
    // each is registered only if present.
    foreach ([
        \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
        \Filament\Actions\ActionsServiceProvider::class,
        \Filament\Forms\FormsServiceProvider::class,
        \Filament\Infolists\InfolistsServiceProvider::class,
        \Filament\Notifications\NotificationsServiceProvider::class,
        \Filament\Schemas\SchemasServiceProvider::class,
        \Filament\Tables\TablesServiceProvider::class,
        \Filament\Widgets\WidgetsServiceProvider::class,
    ] as $provider) {
        if (class_exists($provider)) {
            app()->register($provider);
        }
    }

    $panel = Panel::make()->id('admin')->path('admin')->default()->plugin(SignaturePlugin::make());

    app(PanelRegistry::class)->register($panel);
    require dirname((new ReflectionClass(\Filament\FilamentServiceProvider::class))->getFileName(), 2).'/routes/web.php';
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();

    Filament::setCurrentPanel($panel);
    $panel->boot();

    return $panel;
}

/**
 * The View Signature page is gone: a signature is managed in the launcher
 * drawer. What is left of the resource must not point anywhere that no longer
 * exists, and old /signatures/{record} links must not hand a stranger a hint
 * that a record exists.
 */
describe('signature resource without a View page', function () {

    beforeEach(function () {
        Storage::fake('testing');
    });

    it('registers no view page, so View on the list opens a slide-over', function () {
        expect(SignatureResource::getPages())->not->toHaveKey('view')
            ->and(SignatureResource::getPages())->toHaveKeys(['index', 'create', 'open'])
            ->and(class_exists('Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\ViewSignature'))->toBeFalse();
    });

    it('answers an old link to someone else’s signature with a 404', function () {
        makeUser(51, 'Owner');
        $theirs = makePrimarySignature(51);

        $this->actingAs(makeUser(52, 'Stranger'));

        Livewire::test(OpenSignatureInDrawer::class, ['record' => $theirs->getKey()])->assertStatus(404);
        Livewire::test(OpenSignatureInDrawer::class, ['record' => $theirs->uuid])->assertStatus(404);
    });

    it('sends the owner of an old link to the drawer, opened on that signature', function () {
        signaturePanel();

        $this->actingAs(makeUser(53, 'Owner'));
        $mine = makePrimarySignature(53);

        try {
            (new OpenSignatureInDrawer)->mount($mine->getKey());
            $this->fail('Expected a redirect.');
        } catch (HttpResponseException $e) {
            expect($e->getResponse()->headers->get('Location'))
                ->toEndWith('/admin/signatures?dsig=manage:'.$mine->uuid);
        }
    });

    // The list page is where Filament v3 and v4/v5 differ most (table action
    // classes, record vs. table actions, Schemas vs. Infolists), so it is
    // rendered for real rather than inspected.
    it('lists the user’s signatures with the same row actions on every Filament major', function () {
        signaturePanel();

        $this->actingAs(makeUser(54, 'List Reader'));
        $mine = makePrimarySignature(54);
        makeUser(55, 'Somebody Else');
        $theirs = makePrimarySignature(55);

        Livewire::test(ListSignatures::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableActionVisible('view', $mine)
            ->assertTableActionVisible('download', $mine)
            ->assertTableActionVisible('revoke', $mine);
    });

    it('opens View as a slide-over with the signature details, not a page', function () {
        signaturePanel();

        $this->actingAs(makeUser(56, 'Detail Reader'));
        $mine = makePrimarySignature(56);

        $component = Livewire::test(ListSignatures::class)
            ->mountTableAction('view', $mine)
            ->assertSuccessful();

        $view = $component->instance()->getTable()->getAction('view');

        expect($view->isModalSlideOver())->toBeTrue()
            ->and($view->getUrl())->toBeNull();

        // v4/v5 render action modals apart from the page; v3 renders them in it.
        $details = ['Security Metadata', 'Capture Method', $mine->uuid];

        FilamentVersion::usesSchemas()
            ? $component->assertMountedActionModalSee($details)
            : $component->assertSee($details);
    });

    it('revokes from the list row', function () {
        signaturePanel();

        $this->actingAs(makeUser(57, 'Row Revoker'));
        $mine = makePrimarySignature(57);

        Livewire::test(ListSignatures::class)->callTableAction('revoke', $mine);

        expect($mine->refresh()->isRevoked())->toBeTrue();
    });
});

/**
 * "Signed by me": a real panel page listing what this user has signed, each
 * with the copy they signed and the document as it stands now.
 */
describe('signed documents page', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();
        stubSignatureEmbedding();

        signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);
        app()->instance(\Kukux\DigitalSignature\Tests\Support\LeaveLedger::class, new \Kukux\DigitalSignature\Tests\Support\LeaveLedger([22 => 3]));
    });

    afterEach(fn () => Mockery::close());

    it('renders the copy each user signed, and filters by title', function () {
        signaturePanel();

        $form = app(\Kukux\DigitalSignature\Services\DocumentRouter::class)
            ->route('leave-form', $this->juana, ['period' => '2026-09'])
            ->record();

        $request = $form->latestSigningSession()->requests()->where('slot_key', 'applicant')->first();
        app(\Kukux\DigitalSignature\Services\SigningSessionManager::class)->sign($request, $this->juana->user_id);

        $this->actingAs(\Kukux\DigitalSignature\Tests\Support\TestUser::find($this->juana->user_id));

        Livewire::test(\Kukux\DigitalSignature\Filament\Pages\SignedDocuments::class)
            ->assertOk()
            ->assertSee('Leave form #'.$form->id)
            ->assertSee('The copy I signed')
            ->assertSee(route('signature.request.document', ['signatureRequest' => $request->id]))
            ->set('search', 'nothing like it')
            ->assertDontSee('Leave form #'.$form->id);
    });
});
