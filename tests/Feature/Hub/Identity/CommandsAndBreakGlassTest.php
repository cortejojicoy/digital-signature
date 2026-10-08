<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Hub\Filament\Pages\Profile;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;
use Kukux\DigitalSignature\Hub\Identity\Totp;
use Kukux\DigitalSignature\Hub\Specimens\SpecimenService;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;
use Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support\HubTestUser;
use Livewire\Livewire;

/*
 * signature:prune-provisional, signature:hub-admin, the Profile's signature
 * pad, and break-glass sign-in (RFC 6238 TOTP).
 */

uses(HubIdentityTestCase::class);

beforeEach(function () {
    $this->skipBelowFilament4();

    $this->hubPanels();
});

describe('signature:prune-provisional', function () {

    it('removes provisional accounts whose pairing was never confirmed, and nothing else', function () {
        $abandoned = $this->fromBrowser('a', 'POST', '/signature/hub/pairings')->json();
        $paired = $this->fromBrowser('b', 'POST', '/signature/hub/pairings')->json();
        $agent = $this->agentClaims($paired['user_code']);
        $this->fromBrowser('b', 'POST', "/signature/hub/pairings/{$paired['pairing']}/confirm")->assertOk();

        $abandonedUser = AgentPairing::where('uuid', $abandoned['pairing'])->value('user_id');
        $pairedUser = (int) $agent['user_id'];
        $fresh = $this->fromBrowser('c', 'POST', '/signature/hub/pairings')->json();

        // Only the first two are older than the pairing TTL.
        Identity::whereIn('user_id', [$abandonedUser, $pairedUser])->update(['created_at' => now()->subHour()]);

        $this->artisan('signature:prune-provisional')->expectsOutputToContain('Removed 1')->assertSuccessful();

        expect(HubTestUser::find($abandonedUser))->toBeNull()
            ->and(Identity::forUser($abandonedUser))->toBeNull()
            ->and(HubTestUser::find($pairedUser))->not->toBeNull()
            ->and(HubTestUser::find(AgentPairing::where('uuid', $fresh['pairing'])->value('user_id')))->not->toBeNull();
    });
});

describe('signature:hub-admin', function () {

    it('verifies the identity, assigns super_admin and audits it as console', function () {
        $user = $this->hubUser(42, 'Juan Dela Cruz');
        $this->identified($user, $this->person('Juan Dela Cruz', '2004-0001'), Identity::PENDING);

        $this->artisan('signature:hub-admin', ['emp_no' => '2004-0001'])->assertSuccessful();

        $audit = SignatureAudit::where('event', SignatureAudit::ADMIN_GRANTED)->first();

        expect(Identity::forUser(42)->status)->toBe(Identity::VERIFIED)
            ->and($user->fresh()->hasRole('super_admin'))->toBeTrue()
            ->and($audit->actor_type)->toBe('console')
            ->and($audit->personnel_key)->toBe('p-juan-dela-cruz')
            ->and(SignatureAudit::where('event', SignatureAudit::IDENTITY_VERIFIED)->value('actor_type'))->toBe('console');
    });

    it('fails for someone who never paired, or isn\'t in the registry', function () {
        $this->person('Maria Unpaired', '2010-0001');

        $this->artisan('signature:hub-admin', ['emp_no' => '2010-0001'])->assertFailed();
        $this->artisan('signature:hub-admin', ['emp_no' => '9999-9999'])->assertFailed();
    });
});

describe('Profile signature', function () {

    it('stores a drawn signature and hands it to the specimen service', function () {
        Storage::fake('testing');
        $user = $this->hubUser(42, 'Juan Dela Cruz');
        $this->identified($user, $this->person('Juan Dela Cruz', '2004-0001'), Identity::PENDING);

        $specimens = Mockery::mock(SpecimenService::class);
        $specimens->shouldReceive('published')->once()->with(Mockery::type(Signature::class));
        app()->instance(SpecimenService::class, $specimens);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->assertSee('Draw or upload your signature')
            ->set('newSignature', 'data:image/png;base64,'.base64_encode(stampablePng()))
            ->set('newCertificatePassword', 'secret')
            ->call('createSignature')
            ->assertSet('newSignature', null);

        expect(Signature::primaryActiveFor(42)->count())->toBe(1)
            ->and(SignatureAudit::where('event', SignatureAudit::SIGNATURE_CREATED)->value('personnel_key'))->toBe('p-juan-dela-cruz');
    });
});

describe('break-glass', function () {

    beforeEach(function () {
        // RFC 6238 appendix B's SHA-1 secret, "12345678901234567890".
        $this->secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        config([
            'signature.hub.break_glass.enabled' => true,
            'signature.hub.break_glass.users'   => ['ada@up.edu.ph' => $this->secret],
            'signature.hub.break_glass.ips'     => ['127.0.0.0/8'],
        ]);

        $this->ada = $this->hubUser(1, 'Ada Admin', ['email' => 'ada@up.edu.ph', 'password' => Hash::make('correct horse'), 'roles' => 'super_admin']);
    });

    it('computes RFC 6238 codes', function () {
        // Appendix B: T = 59 → 94287082; T = 1111111109 → 07081804 (last six digits).
        expect(Totp::now($this->secret, 59))->toBe('287082')
            ->and(Totp::now($this->secret, 1111111109))->toBe('081804')
            ->and(Totp::verify($this->secret, '287082', 59 + 30))->toBe(1)
            ->and(Totp::verify($this->secret, '287082', 59 + 90))->toBeNull();
    });

    it('signs a configured super_admin into the admin panel with password + TOTP, audited and alerted', function () {
        Log::spy();

        $this->get('/login')->assertSee('Break-glass sign-in');
        $this->get('/signature/hub/break-glass')->assertOk()->assertSee('Authenticator code');

        $this->post('/signature/hub/break-glass', [
            'email' => 'ada@up.edu.ph', 'password' => 'correct horse', 'code' => Totp::now($this->secret),
        ])->assertRedirect('http://localhost/admin');

        expect(auth()->id())->toBe(1)
            ->and(session(HubAccess::BREAK_GLASS))->toBeTrue()
            // No verified identity, yet the break-glass session opens the admin panel.
            ->and(HubAccess::canUseAdminPanel($this->ada))->toBeTrue()
            ->and(SignatureAudit::where('event', SignatureAudit::BREAK_GLASS_USED)->value('subject_user_id'))->toBe(1);

        Log::shouldHaveReceived('alert')->once();
    });

    it('refuses a wrong code, a reused code, a wrong password and a disallowed IP', function () {
        $code = Totp::now($this->secret);

        $this->post('/signature/hub/break-glass', ['email' => 'ada@up.edu.ph', 'password' => 'correct horse', 'code' => '000000'])
            ->assertSessionHasErrors('email');
        $this->post('/signature/hub/break-glass', ['email' => 'ada@up.edu.ph', 'password' => 'wrong', 'code' => $code])
            ->assertSessionHasErrors('email');

        $this->post('/signature/hub/break-glass', ['email' => 'ada@up.edu.ph', 'password' => 'correct horse', 'code' => $code])->assertRedirect('http://localhost/admin');
        auth()->logout();
        $this->post('/signature/hub/break-glass', ['email' => 'ada@up.edu.ph', 'password' => 'correct horse', 'code' => $code])
            ->assertSessionHasErrors('email');

        config(['signature.hub.break_glass.ips' => ['10.9.9.0/24']]);
        $this->get('/signature/hub/break-glass')->assertNotFound();

        expect(SignatureAudit::where('event', SignatureAudit::BREAK_GLASS_USED)->count())->toBe(1);
    });
});
