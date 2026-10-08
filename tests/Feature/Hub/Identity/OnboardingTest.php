<?php

use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\HubBlock;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Models\Transfer;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;
use Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support\HubTestUser;

/*
 * Pair-first onboarding (plan 1.1–1.3): "Pair this computer" as a guest,
 * confirm from the same browser only, then "Who are you?".
 */

uses(HubIdentityTestCase::class);

beforeEach(function () {
    $this->skipBelowFilament4();

    $this->hubPanels();
});

describe('Pair this computer', function () {

    it('creates a provisional account, its identity and a pairing for a guest', function () {
        $started = $this->fromBrowser('a', 'POST', '/signature/hub/pairings')->assertCreated()->json();

        $pairing = AgentPairing::where('uuid', $started['pairing'])->firstOrFail();
        $user = HubTestUser::findOrFail($pairing->user_id);
        $identity = Identity::forUser($user->id);

        expect($started['user_code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
            ->and($started['link'])->toStartWith('kukuxsign://pair?o=')
            ->and($user->name)->toBe('Unidentified')
            ->and($user->email)->toEndWith('@hub.invalid')
            ->and($identity->status)->toBe(Identity::UNIDENTIFIED)
            ->and($identity->session_hash)->toHaveLength(64)
            ->and($this->browserUserId('a'))->toBeNull();
    });

    it('lets only the browser that started it see and confirm the claimed computer, then signs it in', function () {
        $started = $this->fromBrowser('a', 'POST', '/signature/hub/pairings')->json();

        $this->fromBrowser('a', 'GET', "/signature/hub/pairings/{$started['pairing']}")
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('claim', null);

        $agent = $this->agentClaims($started['user_code']);

        // Another browser can't see it, or confirm it.
        $this->fromBrowser('b', 'GET', "/signature/hub/pairings/{$started['pairing']}")->assertNotFound();
        $this->fromBrowser('b', 'POST', "/signature/hub/pairings/{$started['pairing']}/confirm")->assertNotFound()
            ->assertJsonPath('error', 'not_found');

        // The starting browser sees the computer exactly as describeClaim() tells it.
        $this->fromBrowser('a', 'GET', "/signature/hub/pairings/{$started['pairing']}")
            ->assertOk()
            ->assertJsonPath('status', 'awaiting_confirmation')
            ->assertJsonPath('claim.label', 'Juan’s MacBook Air')
            ->assertJsonPath('claim.protection', 'Secure Enclave')
            ->assertJsonPath('claim.presence', true)
            ->assertJsonPath('claim.hub_blocked', false);

        $confirmed = $this->fromBrowser('a', 'POST', "/signature/hub/pairings/{$started['pairing']}/confirm", ['device_type' => 'laptop'])
            ->assertOk()->assertJsonPath('status', 'confirmed')->json();

        $userId = (int) $agent['user_id'];

        expect($confirmed['redirect'])->toBe('http://localhost/identify')
            ->and($this->browserUserId('a'))->toBe($userId)
            ->and($this->browserUserId('b'))->toBeNull()
            ->and(SigningDevice::where('user_id', $userId)->where('kind', 'agent')->active()->count())->toBe(1);

        // The agent picks up its token as usual.
        $this->agentPollsToken($agent);
    });

    it('refuses a computer blocked after a rejected claim', function () {
        HubBlock::create(['hardware_id_hash' => str_repeat('ab', 32), 'reason' => 'identity_rejected', 'until' => now()->addDays(30)]);

        $started = $this->fromBrowser('a', 'POST', '/signature/hub/pairings')->json();
        $this->agentClaims($started['user_code']);

        $this->fromBrowser('a', 'GET', "/signature/hub/pairings/{$started['pairing']}")->assertJsonPath('claim.hub_blocked', true);
        $this->fromBrowser('a', 'POST', "/signature/hub/pairings/{$started['pairing']}/confirm")
            ->assertStatus(422)->assertJsonPath('error', 'computer_blocked');

        expect(SigningDevice::count())->toBe(0)
            ->and(AgentPairing::where('uuid', $started['pairing'])->value('status'))->toBe('rejected')
            ->and($this->browserUserId('a'))->toBeNull();
    });

    it('limits pairings per IP per hour', function () {
        config(['signature.hub.pair_rate_limit' => 2]);

        $this->fromBrowser('a', 'POST', '/signature/hub/pairings')->assertCreated();
        $this->fromBrowser('b', 'POST', '/signature/hub/pairings')->assertCreated();
        $this->fromBrowser('c', 'POST', '/signature/hub/pairings')->assertStatus(429)->assertJsonPath('error', 'rate_limited');

        expect(Identity::count())->toBe(2);
    });
});

describe('EnsureIdentified', function () {

    it('lets an unidentified account open only "Who are you?"', function () {
        $user = $this->hubUser(10);
        Identity::create(['user_id' => $user->id, 'status' => Identity::UNIDENTIFIED]);

        $this->actingAs($user)->get('/')->assertRedirect('http://localhost/identify');
        $this->actingAs($user)->get('/identify')->assertOk()->assertSee('Who are you?');
    });

    it('lets an identified account through to its Profile', function () {
        $user = $this->hubUser(11, 'Juan Dela Cruz');
        $this->identified($user, $this->person('Juan Dela Cruz', '2004-0001'), Identity::PENDING);

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Waiting for verification');
        $this->actingAs($user)->get('/identify')->assertRedirect('http://localhost');
    });
});

describe('Who are you?', function () {

    it('searches active people only, by 3+ characters or an exact employee number, with minimal fields', function () {
        $this->person('Juan Dela Cruz', '2004-0001');
        $this->person('Juana Reyes', '2004-0002');
        $this->person('Juan Retired', '1990-0001', ['is_active' => false]);
        $user = $this->hubUser(12);
        $service = app(IdentityService::class);

        expect($service->search($user->id, 'Ju'))->toBe([])
            ->and(collect($service->search($user->id, 'juan'))->pluck('name')->all())->toBe(['Juan Dela Cruz', 'Juana Reyes'])
            ->and(collect($service->search($user->id, '2004-0002'))->pluck('name')->all())->toBe(['Juana Reyes'])
            ->and(collect($service->search($user->id, '2004-000'))->pluck('name')->all())->toBe([])
            ->and($service->search($user->id, '1990-0001'))->toBe([]);

        // The page itself never sends the personnel key to the browser.
        $this->actingAs($user);

        Livewire\Livewire::test(\Kukux\DigitalSignature\Hub\Filament\Pages\Identify::class)
            ->set('query', 'juan')
            ->call('search')
            ->assertSet('results', [
                ['name' => 'Juan Dela Cruz', 'unit' => 'Institute of Computer Science', 'position' => 'Assistant Professor'],
                ['name' => 'Juana Reyes', 'unit' => 'Institute of Computer Science', 'position' => 'Assistant Professor'],
            ])
            ->assertDontSee('p-juan-dela-cruz');
    });

    it('caps searches per account (R9)', function () {
        config(['signature.hub.personnel.search_quota' => 2]);
        Log::spy();
        $user = $this->hubUser(13);

        app(IdentityService::class)->search($user->id, 'juan');
        app(IdentityService::class)->search($user->id, 'x');

        expect(fn () => app(IdentityService::class)->search($user->id, 'juan'))
            ->toThrow(\Kukux\DigitalSignature\Hub\Identity\IdentityException::class, 'search limit');
    });

    it('links an unclaimed person, copies their name and email, and waits for verification', function () {
        $this->person('Juan Dela Cruz', '2004-0001');
        $user = $this->hubUser(14, 'Unidentified', ['email' => 'unidentified+x@hub.invalid']);
        $this->actingAs($user);

        Livewire\Livewire::test(\Kukux\DigitalSignature\Hub\Filament\Pages\Identify::class)
            ->set('query', 'Dela Cruz')
            ->call('search')
            ->call('choose', 0)
            ->assertRedirect('http://localhost');

        $identity = Identity::forUser($user->id);

        expect($identity->status)->toBe(Identity::PENDING)
            ->and($identity->personnel_key)->toBe('p-juan-dela-cruz')
            ->and($user->fresh()->name)->toBe('Juan Dela Cruz')
            ->and($user->fresh()->email)->toBe('juan.dela.cruz@up.edu.ph')
            ->and(SignatureAudit::where('event', SignatureAudit::IDENTITY_CLAIMED)->where('personnel_key', 'p-juan-dela-cruz')->exists())->toBeTrue();
    });

    it('verifies straight away when verification is off', function () {
        config(['signature.hub.require_verification' => false]);
        $this->person('Juan Dela Cruz', '2004-0001');
        $user = $this->hubUser(15);

        expect(app(IdentityService::class)->identify($user->id, 'p-juan-dela-cruz')->status)->toBe(Identity::VERIFIED);
    });

    it('turns a claim on someone already linked into a transfer to their old computer', function () {
        $juan = $this->person('Juan Dela Cruz', '2004-0001');
        $old = $this->hubUser(16, 'Juan Dela Cruz');
        $this->identified($old, $juan);
        $this->pairComputer($old, ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Office MacBook Pro']);

        $new = $this->hubUser(17);
        $result = app(IdentityService::class)->identify($new->id, $juan->uuid);

        expect($result)->toBeInstanceOf(Transfer::class)
            ->and($result->status)->toBe('pending')
            ->and($result->agentJob->user_id)->toBe($old->id)
            ->and($result->agentJob->purpose)->toBe('transfer')
            ->and($result->agentJob->title)->toBe(IdentityTransfer::JOB_TITLE)
            ->and($result->agentJob->meta['transfer']['name'])->toBe('Juan Dela Cruz')
            ->and(Identity::forUser($new->id)->status)->toBe(Identity::UNIDENTIFIED)
            ->and(SignatureAudit::where('event', SignatureAudit::TRANSFER_REQUESTED)->exists())->toBeTrue();
    });

    it('sends the transfer to the admins when the old account has no computer', function () {
        $juan = $this->person('Juan Dela Cruz', '2004-0001');
        $this->identified($this->hubUser(18, 'Juan Dela Cruz'), $juan);

        $transfer = app(IdentityService::class)->identify($this->hubUser(19)->id, $juan->uuid);

        expect($transfer->agent_job_id)->toBeNull()->and($transfer->status)->toBe('pending');
    });
});
