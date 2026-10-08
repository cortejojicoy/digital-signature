<?php

use Kukux\DigitalSignature\Hub\Filament\Pages\PendingClaims;
use Kukux\DigitalSignature\Hub\Filament\Pages\Profile;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Models\Transfer;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;
use Livewire\Livewire;

/*
 * A new computer for someone already linked (plan 1.6, R11): the old
 * computer approves through a `transfer` agent job, or an admin does when
 * it's lost. The person, their signature and their `sub` move together.
 */

uses(HubIdentityTestCase::class);

beforeEach(function () {
    $this->hubPanels();

    $this->notifier = Mockery::mock(HubNotifier::class);
    $this->notifier->shouldReceive('signatureUpdated')->byDefault();
    app()->instance(HubNotifier::class, $this->notifier);

    $this->juan = $this->person('Juan Dela Cruz', '2004-0001');
    $this->old = $this->hubUser(42, 'Juan Dela Cruz', ['email' => 'juan.dela.cruz@up.edu.ph']);
    $this->identified($this->old, $this->juan);
    $this->oldAgent = $this->pairComputer($this->old, ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Office MacBook Pro']);
    $this->signature = makePrimarySignature($this->old->id);

    $this->new = $this->hubUser(43, 'Unidentified', ['email' => 'unidentified+43@hub.invalid']);
    Identity::create(['user_id' => $this->new->id, 'status' => Identity::UNIDENTIFIED]);
    $this->newAgent = $this->pairComputer($this->new, ['label' => 'Juan’s MacBook Air']);
});

it('moves the person to the new computer once the old one approves', function () {
    $this->notifier->shouldReceive('signatureUpdated')->once()->with('p-juan-dela-cruz');

    $transfer = app(IdentityService::class)->identify($this->new->id, $this->juan->uuid);
    expect($transfer->agentJob->meta['transfer'])->toBe(['name' => 'Juan Dela Cruz', 'device' => 'Juan’s MacBook Air']);

    // On the old computer, the Profile offers the approval link.
    $this->actingAs($this->old);
    $link = Livewire::test(Profile::class)
        ->assertSee('A new computer wants to take over your signature')
        ->call('approveTransfer', $transfer->id)
        ->effects['redirect'] ?? '';

    expect($link)->toStartWith("kukuxsign://job/{$transfer->agentJob->uuid}?t=");
    parse_str(parse_url($link, PHP_URL_QUERY), $query);

    // The old computer's agent claims the job, sees the transfer, approves it.
    $job = $this->agentRequest($this->oldAgent, 'POST', "/signature/agent/jobs/{$transfer->agentJob->uuid}/claim", ['link_token' => $query['t']])
        ->assertOk()
        ->assertJsonPath('purpose', 'transfer')
        ->assertJsonPath('document.title', IdentityTransfer::JOB_TITLE)
        ->assertJsonPath('transfer.name', 'Juan Dela Cruz')
        ->json();

    $this->agentRequest($this->oldAgent, 'POST', "/signature/agent/jobs/{$job['uuid']}/complete", [
        'proof' => $this->agentSignature($this->oldAgent['identity'], DeviceProofVerifier::message('transfer', $job['nonce'], $job['user_id'], $job['payload_hash'])),
    ])->assertOk();

    $new = Identity::forUser($this->new->id);

    expect($transfer->fresh()->status)->toBe('approved')
        // The personnel link and the `sub`: same person, new account.
        ->and($new->personnel_key)->toBe('p-juan-dela-cruz')
        ->and($new->status)->toBe(Identity::VERIFIED)
        ->and(app(IdentityService::class)->userIdFor('p-juan-dela-cruz'))->toBe($this->new->id)
        ->and(app(IdentityService::class)->personnelKeyFor($this->new->id))->toBe('p-juan-dela-cruz')
        // The old account is retired, its email freed for the new one.
        ->and(Identity::forUser($this->old->id)->status)->toBe(Identity::RETIRED)
        ->and($this->new->fresh()->email)->toBe('juan.dela.cruz@up.edu.ph')
        ->and($this->new->fresh()->name)->toBe('Juan Dela Cruz')
        // The signature row follows; the old computer is released.
        ->and($this->signature->fresh()->user_id)->toBe($this->new->id)
        ->and($this->oldAgent['device']->fresh()->isActive())->toBeFalse()
        ->and($this->newAgent['device']->fresh()->isActive())->toBeTrue()
        // One history for the person, across both accounts.
        ->and(SignatureAudit::where('personnel_key', 'p-juan-dela-cruz')->pluck('event')->all())
        ->toContain(SignatureAudit::TRANSFER_REQUESTED, SignatureAudit::TRANSFER_APPROVED, SignatureAudit::AGENT_RELEASED);
});

it('refuses the move when the old computer declines', function () {
    $transfer = app(IdentityService::class)->identify($this->new->id, $this->juan->uuid);
    $token = 'tok';
    $transfer->agentJob->update(['link_token_hash' => hash('sha256', $token)]);

    $job = $this->agentRequest($this->oldAgent, 'POST', "/signature/agent/jobs/{$transfer->agentJob->uuid}/claim", ['link_token' => $token])->json();
    $this->agentRequest($this->oldAgent, 'POST', "/signature/agent/jobs/{$job['uuid']}/reject", ['reason' => 'declined'])->assertOk();

    expect($transfer->fresh()->status)->toBe('rejected')
        ->and(Identity::forUser($this->old->id)->status)->toBe(Identity::VERIFIED)
        ->and(Identity::forUser($this->new->id)->status)->toBe(Identity::UNIDENTIFIED)
        ->and($this->signature->fresh()->user_id)->toBe($this->old->id)
        ->and(SignatureAudit::where('event', SignatureAudit::TRANSFER_REJECTED)->exists())->toBeTrue();
});

it('lets an admin approve the move when the old computer is lost', function () {
    $this->notifier->shouldReceive('signatureUpdated')->once()->with('p-juan-dela-cruz');

    $admin = $this->hubUser(1, 'Admin', ['roles' => 'super_admin']);
    $this->identified($admin, $this->person('Ada Admin', '2000-0001'));

    // The old computer is gone.
    app(\Kukux\DigitalSignature\Agent\AgentPairingService::class)->release($this->oldAgent['device']->fresh());
    $transfer = app(IdentityService::class)->identify($this->new->id, $this->juan->uuid);
    expect($transfer->agent_job_id)->toBeNull();

    $this->actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    Livewire::test(PendingClaims::class)
        ->assertSee('Juan Dela Cruz')
        ->assertSee('none (lost or released)')
        ->call('approveTransfer', $transfer->id);

    expect($transfer->fresh()->status)->toBe('approved')
        ->and($transfer->fresh()->decided_by)->toBe($admin->id)
        ->and(Identity::forUser($this->new->id)->status)->toBe(Identity::VERIFIED)
        ->and(Identity::forUser($this->new->id)->verified_by)->toBe($admin->id)
        ->and(SigningDevice::where('user_id', $this->old->id)->active()->count())->toBe(0);
});

it('shows the new computer that the move waits on the old one', function () {
    app(IdentityService::class)->identify($this->new->id, $this->juan->uuid);

    $this->actingAs($this->new);

    Livewire::test(\Kukux\DigitalSignature\Hub\Filament\Pages\Identify::class)
        ->assertSee('Juan Dela Cruz already has a signing computer: Office MacBook Pro')
        ->call('cancelTransfer');

    expect(Transfer::first()->status)->toBe('rejected');
});
