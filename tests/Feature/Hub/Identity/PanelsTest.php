<?php

use Filament\Facades\Filament;
use Kukux\DigitalSignature\Hub\Filament\HubPanels;
use Kukux\DigitalSignature\Hub\Filament\Pages\Apps;
use Kukux\DigitalSignature\Hub\Filament\Pages\AuditLog;
use Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelDirectoryPage;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;
use Livewire\Livewire;

/*
 * The hub's two panels (plan 1.8): the person panel with no topbar or
 * sidebar, the admin panel for verified super_admins, and where people land.
 */

uses(HubIdentityTestCase::class);

beforeEach(function () {
    [$this->personPanel, $this->adminPanel] = $this->hubPanels();

    $this->admin = $this->hubUser(1, 'Ada Admin', ['roles' => 'super_admin']);
    $this->identified($this->admin, $this->person('Ada Admin', '2000-0001'));

    $this->juan = $this->hubUser(42, 'Juan Dela Cruz');
    $this->identified($this->juan, $this->person('Juan Dela Cruz', '2004-0001'));
});

it('remembers the panel ids it was registered on', function () {
    expect(HubPanels::personPanelId())->toBe('hub')
        ->and(HubPanels::adminPanelId())->toBe('admin')
        ->and($this->personPanel->hasTopbar())->toBeFalse()
        ->and($this->personPanel->hasNavigation())->toBeFalse();
});

it('sends a guest at /admin to the landing, which has no password form', function () {
    $this->get('/admin/personnel')->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertRedirect('http://localhost/login');

    $this->get('/login')
        ->assertOk()
        ->assertSee('Pair this computer')
        ->assertSee('Sign in with your computer')
        ->assertDontSee('type="password"', false)
        ->assertDontSee('Break-glass');

    expect(collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter()
        ->filter(fn (string $name) => str_contains($name, 'password-reset') || str_contains($name, 'auth.register')))->toBeEmpty();
});

it('lets only a verified super_admin into the admin panel', function () {
    $this->actingAs($this->juan)->get('/admin/personnel')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/personnel')->assertOk();

    Identity::forUser($this->admin->id)->update(['status' => Identity::PENDING]);
    $this->actingAs($this->admin)->get('/admin/personnel')->assertForbidden();

    expect(HubAccess::canAccessPanel($this->juan, 'hub'))->toBeTrue();
    Identity::forUser($this->juan->id)->update(['status' => Identity::SEPARATED]);
    expect(HubAccess::canAccessPanel($this->juan->fresh(), 'hub'))->toBeFalse();
});

it('renders the person panel with no topbar or sidebar, and the admin panel with both', function () {
    $profile = $this->actingAs($this->juan)->get('/')->assertOk()->getContent();

    expect($profile)->toContain('Juan Dela Cruz')
        ->toContain('Sign out')
        ->not->toContain('fi-body-has-topbar')
        ->not->toContain('fi-body-has-navigation')
        ->not->toContain('fi-main-sidebar')
        ->not->toContain('Open admin panel')
        // No launcher on the hub's person panel.
        ->not->toContain('kukux-digital-signature.launcher');

    $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Open admin panel');

    $admin = $this->actingAs($this->admin)->get('/admin/personnel')->assertOk()->getContent();
    expect($admin)->toContain('fi-body-has-topbar')->toContain('fi-main-sidebar')->toContain('My profile');
});

it('sends people where they belong after sign-in', function () {
    $redirector = app(HubRedirector::class);

    expect($redirector->afterSignIn($this->juan))->toBe('http://localhost')
        ->and($redirector->afterSignIn($this->admin))->toBe('http://localhost/admin');

    $unidentified = $this->hubUser(50);
    Identity::create(['user_id' => $unidentified->id, 'status' => Identity::UNIDENTIFIED]);
    expect($redirector->afterSignIn($unidentified))->toBe('http://localhost/identify');

    // An app's authorize URL wins over the admin redirect…
    session(['url.intended' => 'http://localhost/signature/hub/oauth/authorize?client_id=amp']);
    expect($redirector->afterSignIn($this->admin))->toBe('http://localhost/signature/hub/oauth/authorize?client_id=amp');

    // …but an intended admin URL doesn't send a non-admin to a 403.
    session(['url.intended' => 'http://localhost/admin/apps']);
    expect($redirector->afterSignIn($this->juan))->toBe('http://localhost');
});

it('lists personnel with their hub status, filterable', function () {
    $this->person('Maria Unclaimed', '2010-0001', ['unit_name' => 'Library']);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel($this->adminPanel);

    Livewire::test(PersonnelDirectoryPage::class)
        ->assertSuccessful()
        ->assertSee(['Juan Dela Cruz', 'Maria Unclaimed', 'Verified', 'Unclaimed'])
        ->filterTable('hub_status', 'unclaimed')
        ->assertSee('Maria Unclaimed')
        ->assertDontSee('Juan Dela Cruz')
        ->resetTableFilters()
        ->filterTable('unit', 'Library')
        ->assertDontSee('Juan Dela Cruz');
});

it('shows the audit log with when/what/where/how/outcome and exports it as CSV', function () {
    SignatureAudit::record(SignatureAudit::LOGIN_APPROVED, [
        'subject_user_id' => $this->juan->id, 'ip' => '10.1.2.3', 'app' => 'performance',
        'context' => ['purpose' => 'login', 'browser' => 'Chrome on macOS'],
    ]);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel($this->adminPanel);

    $component = Livewire::test(AuditLog::class)
        ->assertSuccessful()
        ->assertSee(['Signed in', 'performance', '10.1.2.3', 'proof: login', 'Approved'])
        ->filterTable('app', 'performance');

    $csv = $component->instance()->exportCsv();
    ob_start();
    $csv->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('when,event,what,personnel_key,app,where,how,outcome')
        ->toContain('login.approved,"Signed in",p-juan-dela-cruz,performance');
});

it('registers an app and shows its secrets once', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel($this->adminPanel);

    $component = Livewire::test(Apps::class)
        ->set('clientId', 'performance')
        ->set('name', 'Performance')
        ->set('redirectUris', "https://performance.test/callback\n")
        ->set('webhookUrl', 'https://performance.test/webhook')
        ->call('create')
        ->assertSee('they are not shown again');

    $app = HubApp::where('client_id', 'performance')->firstOrFail();
    expect($app->checkSecret($component->get('secrets')['client_secret']))->toBeTrue()
        ->and($app->redirect_uris)->toBe(['https://performance.test/callback']);

    $component->call('dismissSecrets')->assertSet('secrets', null)->assertSee('Performance');
});
