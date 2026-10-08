<?php

use Kukux\DigitalSignature\Hub\Filament\Pages\PendingClaims;
use Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelProfile;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar;
use Kukux\DigitalSignature\Hub\Specimens\HubRevocation;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Models\HubBlock;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;
use Livewire\Livewire;

/*
 * The verification gate (plan 1.3, D9, D11, R8): a claim waits for an admin;
 * until then the hub refuses app sign-in; reject releases and blocks the
 * computer; separation stops everything.
 */

uses(HubIdentityTestCase::class);

beforeEach(function () {
    $this->skipBelowFilament4();

    $this->hubPanels();

    $this->admin = $this->hubUser(1, 'Ada Admin', ['roles' => 'super_admin']);
    $this->identified($this->admin, $this->person('Ada Admin', '2000-0001'));

    $this->juan = $this->hubUser(42, 'Juan Dela Cruz', ['email' => 'juan.dela.cruz@up.edu.ph']);
    $this->identity = $this->identified($this->juan, $this->person('Juan Dela Cruz', '2004-0001'), Identity::PENDING);
    $this->agent = $this->pairComputer($this->juan);

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
});

it('refuses app sign-in (OIDC authorize) while the claim is pending', function () {
    $app = app(HubAppRegistrar::class)->create('performance', 'Performance', ['https://performance.test/callback']);
    $query = http_build_query([
        'response_type' => 'code', 'client_id' => 'performance', 'redirect_uri' => 'https://performance.test/callback',
        'state' => 'xyz', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
    ]);

    expect(app(IdentityService::class)->isVerified($this->juan->id))->toBeFalse();
    $this->actingAs($this->juan)->get("/signature/hub/oauth/authorize?{$query}")->assertForbidden();

    app(IdentityService::class)->verify($this->juan->id, $this->admin->id);

    expect(app(IdentityService::class)->isVerified($this->juan->id))->toBeTrue();
    $this->actingAs($this->juan)->get("/signature/hub/oauth/authorize?{$query}")->assertRedirect();
});

it('sends a guest at authorize to the landing, keeping the intended URL', function () {
    app(HubAppRegistrar::class)->create('performance', 'Performance', ['https://performance.test/callback']);

    $this->get('/signature/hub/oauth/authorize?client_id=performance&redirect_uri='.urlencode('https://performance.test/callback'))
        ->assertRedirect('http://localhost/signature/hub/landing')
        ->assertSessionHas('url.intended', fn (string $url) => str_contains($url, '/signature/hub/oauth/authorize?client_id=performance'));

    $this->get('/signature/hub/landing')->assertRedirect('http://localhost/login');
});

it('verifies a claim from the Pending claims queue', function () {
    $this->actingAs($this->admin);

    Livewire::test(PendingClaims::class)
        ->assertSee('Juan Dela Cruz')
        ->call('verify', $this->juan->id);

    expect($this->identity->fresh()->status)->toBe(Identity::VERIFIED)
        ->and($this->identity->fresh()->verified_by)->toBe($this->admin->id)
        ->and(SignatureAudit::where('event', SignatureAudit::IDENTITY_VERIFIED)->where('personnel_key', 'p-juan-dela-cruz')->value('actor_user_id'))->toBe($this->admin->id);
});

it('rejects a claim: releases the computer, blocks it, frees the name', function () {
    $this->actingAs($this->admin);

    Livewire::test(PendingClaims::class)->call('reject', $this->juan->id);

    expect($this->identity->fresh()->status)->toBe(Identity::REJECTED)
        ->and($this->agent['device']->fresh()->isActive())->toBeFalse()
        ->and(HubBlock::isBlocked(str_repeat('ab', 32)))->toBeTrue()
        ->and($this->juan->fresh()->email)->toEndWith('@hub.invalid')
        ->and(Identity::current('p-juan-dela-cruz'))->toBeNull()
        ->and(SignatureAudit::where('event', SignatureAudit::IDENTITY_REJECTED)->exists())->toBeTrue()
        ->and(SignatureAudit::where('event', SignatureAudit::AGENT_RELEASED)->exists())->toBeTrue();

    // A rejected account is out of the person panel.
    $this->actingAs($this->juan)->get('/')->assertForbidden();
});

it('verifies and releases from the per-person page', function () {
    $this->actingAs($this->admin);

    Livewire::test(PersonnelProfile::class, ['key' => 'p-juan-dela-cruz'])
        ->assertSee('Juan Dela Cruz')
        ->assertSee('Juan’s MacBook Air')
        ->call('verify')
        ->call('releaseDevice', $this->agent['device']->uuid);

    expect($this->identity->fresh()->status)->toBe(Identity::VERIFIED)
        ->and($this->agent['device']->fresh()->isActive())->toBeFalse();
});

it('separates a person when HR says they left', function () {
    $revocation = Mockery::mock(HubRevocation::class);
    $revocation->shouldReceive('revokeSignature')->once()->with($this->juan->id, 'separated', null, null);
    app()->instance(HubRevocation::class, $revocation);

    $notifier = Mockery::mock(HubNotifier::class);
    $notifier->shouldReceive('personSeparated')->once()->with('p-juan-dela-cruz');
    app()->instance(HubNotifier::class, $notifier);

    app(IdentityService::class)->separate('p-juan-dela-cruz');

    expect($this->identity->fresh()->status)->toBe(Identity::SEPARATED)
        ->and($this->agent['device']->fresh()->isActive())->toBeFalse()
        ->and(app(IdentityService::class)->userIdFor('p-juan-dela-cruz'))->toBeNull()
        ->and(SignatureAudit::where('event', SignatureAudit::IDENTITY_SEPARATED)->exists())->toBeTrue();
});
