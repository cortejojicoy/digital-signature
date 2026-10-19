<?php

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction;
use Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Filament\Livewire\SignatureLauncher;
use Kukux\DigitalSignature\Filament\Livewire\SigningDevices;
use Kukux\DigitalSignature\Filament\Pages\V4\PdfTemplateDesigner;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;
use Kukux\DigitalSignature\Models\FeatureRule;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Models\UserPreference;
use Kukux\DigitalSignature\Support\FeatureAccess;
use Kukux\DigitalSignature\Tests\Support\LeaveForm;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;
use Livewire\Livewire;

/**
 * Feature access: a super_admin limits a feature to selected people from the
 * launcher's Settings tab while it's being tested. Everything is open until
 * someone changes it, so an app that never opens the tab behaves as before.
 */
beforeEach(function () {
    // User 1 is the super_admin throughout.
    HubAccess::superAdminUsing(fn ($user): bool => (int) $user->getAuthIdentifier() === 1);

    $this->admin = makeUser(1, 'Ada Admin');
    $this->pilot = makeUser(2, 'Pia Pilot');
    $this->other = makeUser(3, 'Oscar Other');
});

afterEach(fn () => HubAccess::superAdminUsing(null));

function access(): FeatureAccess
{
    return app(FeatureAccess::class);
}

describe('FeatureAccess', function () {

    it('opens every feature to everyone until a super_admin changes it', function () {
        foreach (array_keys(FeatureAccess::features()) as $feature) {
            expect(FeatureAccess::allows($feature, $this->other))->toBeTrue()
                ->and(FeatureAccess::allows($feature))->toBeTrue();
        }
    });

    it('limits a selected feature to the people picked, plus super admins', function () {
        access()->setMode(FeatureAccess::ROUTE_FOR_SIGNATURES, FeatureAccess::SELECTED);
        access()->grant(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->pilot->id);

        expect(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->pilot))->toBeTrue()
            ->and(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->admin))->toBeTrue()
            ->and(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->other))->toBeFalse()
            ->and(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES))->toBeFalse()
            // Other features are untouched.
            ->and(FeatureAccess::allows(FeatureAccess::SIGN_DOCUMENT, $this->other))->toBeTrue();
    });

    it('limits a feature to super admins only', function () {
        access()->grant(FeatureAccess::SIGN_DOCUMENT, $this->pilot->id);
        access()->setMode(FeatureAccess::SIGN_DOCUMENT, FeatureAccess::SUPER_ADMINS);

        expect(FeatureAccess::allows(FeatureAccess::SIGN_DOCUMENT, $this->admin))->toBeTrue()
            ->and(FeatureAccess::allows(FeatureAccess::SIGN_DOCUMENT, $this->pilot))->toBeFalse();
    });

    it('keeps the people picked when the mode changes, so a test can be resumed', function () {
        access()->setMode(FeatureAccess::DESKTOP_AGENT, FeatureAccess::SELECTED);
        access()->grant(FeatureAccess::DESKTOP_AGENT, $this->pilot->id);
        access()->setMode(FeatureAccess::DESKTOP_AGENT, FeatureAccess::EVERYONE);
        access()->setMode(FeatureAccess::DESKTOP_AGENT, FeatureAccess::SELECTED);

        expect(access()->userIds(FeatureAccess::DESKTOP_AGENT))->toBe(['2']);
    });

    it('revokes one person and leaves the rest', function () {
        access()->setMode(FeatureAccess::DESKTOP_AGENT, FeatureAccess::SELECTED);
        access()->grant(FeatureAccess::DESKTOP_AGENT, $this->pilot->id);
        access()->grant(FeatureAccess::DESKTOP_AGENT, $this->other->id);
        access()->revoke(FeatureAccess::DESKTOP_AGENT, $this->pilot->id);

        expect(FeatureAccess::allows(FeatureAccess::DESKTOP_AGENT, $this->pilot))->toBeFalse()
            ->and(FeatureAccess::allows(FeatureAccess::DESKTOP_AGENT, $this->other))->toBeTrue();
    });

    it('never gates a feature it does not know', function () {
        expect(FeatureAccess::allows('something-else', $this->other))->toBeTrue();
        expect(fn () => access()->setMode('something-else', FeatureAccess::SELECTED))->toThrow(InvalidArgumentException::class);
        expect(fn () => access()->setMode(FeatureAccess::SIGN_DOCUMENT, 'sometimes'))->toThrow(InvalidArgumentException::class);
    });

    it('keeps everything open on a database that has not been migrated yet', function () {
        Schema::drop('digital_signature_feature_access');

        expect(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->other))->toBeTrue();
    });
});

describe('gated surfaces', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();

        $this->boss = signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);
        app()->instance(LeaveLedger::class, new LeaveLedger([22 => 3]));

        access()->setMode(FeatureAccess::ROUTE_FOR_SIGNATURES, FeatureAccess::SELECTED);
        access()->grant(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->pilot->id);
    });

    it('hides Route for signatures from people not picked, and refuses it if called anyway', function () {
        $this->actingAs($this->other);

        $action = RouteForSignaturesAction::make()->document('leave-form')->subject($this->juana)->context(['period' => '2026-09']);

        expect($action->isVisible())->toBeFalse()
            ->and(fn () => $action->call())->toThrow(Halt::class);

        expect(LeaveForm::count())->toBe(0)
            ->and(SigningSession::count())->toBe(0);
    });

    it('routes for someone picked', function () {
        $this->actingAs($this->pilot);

        $action = RouteForSignaturesAction::make()->document('leave-form')->subject($this->juana)->context(['period' => '2026-09']);

        expect($action->isVisible())->toBeTrue();

        $action->call();

        expect(SigningSession::count())->toBe(1);
    });

    it('gates Request signatures under the same feature', function () {
        $this->actingAs($this->other);
        $form = LeaveForm::create(['person_id' => 22, 'period' => '2026-09', 'reviewer_signatory_id' => 21]);

        $action = RequestSignaturesAction::make()->autoAffix(false);

        expect($action->isVisible())->toBeFalse()
            ->and(fn () => $action->call(['record' => $form]))->toThrow(Halt::class);

        expect(SigningSession::count())->toBe(0);
    });

    it('hides Sign Document when it is limited', function () {
        access()->setMode(FeatureAccess::SIGN_DOCUMENT, FeatureAccess::SUPER_ADMINS);

        $this->actingAs($this->other);
        expect(SignDocumentAction::make()->isVisible())->toBeFalse();

        $this->actingAs($this->admin);
        expect(SignDocumentAction::make()->isVisible())->toBeTrue();
    });

    it('refuses the placement designer to people not picked', function () {
        access()->setMode(FeatureAccess::TEMPLATE_DESIGNER, FeatureAccess::SUPER_ADMINS);
        $this->actingAs($this->other);

        Livewire::test(PdfTemplateDesigner::class, ['templateKey' => 'leave-form'])->assertForbidden();
    });

    it('hides and refuses desktop agent pairing to people not picked', function () {
        config(['signature.devices.agent.enabled' => true]);
        access()->setMode(FeatureAccess::DESKTOP_AGENT, FeatureAccess::SUPER_ADMINS);
        $this->actingAs($this->other);

        Livewire::test(SigningDevices::class)
            ->assertDontSee('Pair desktop agent')
            ->call('startPairing')
            ->assertSet('pairingUuid', null)
            ->assertSee('turned on for selected people only');
    });
});

describe('the Settings tab', function () {

    it('is only offered to super admins', function () {
        $this->actingAs($this->other);
        expect(Livewire::test(SignatureLauncher::class)->call('loadRequests')->html())
            ->not->toContain('Feature access')
            ->not->toContain("tab = 'settings'");

        $this->actingAs($this->admin);
        expect(Livewire::test(SignatureLauncher::class)->call('loadRequests')->html())
            ->toContain('Feature access')
            ->toContain('Route for signatures')
            ->toContain('Floating button');
    });

    it('lets a super_admin pick people for a feature and remove them', function () {
        $this->actingAs($this->admin);

        $component = Livewire::test(SignatureLauncher::class)
            ->call('loadRequests')
            ->call('setFeatureMode', FeatureAccess::ROUTE_FOR_SIGNATURES, FeatureAccess::SELECTED)
            ->assertSee('Nobody selected yet')
            ->set('featureSearch.'.FeatureAccess::ROUTE_FOR_SIGNATURES, 'pia')
            ->assertSee('pia-pilot@example.test')
            ->assertDontSee('Oscar Other')
            ->call('grantFeature', FeatureAccess::ROUTE_FOR_SIGNATURES, '2')
            ->assertSet('featureSearch.'.FeatureAccess::ROUTE_FOR_SIGNATURES, '');

        expect(access()->mode(FeatureAccess::ROUTE_FOR_SIGNATURES))->toBe(FeatureAccess::SELECTED)
            ->and(access()->userIds(FeatureAccess::ROUTE_FOR_SIGNATURES))->toBe(['2'])
            ->and(FeatureRule::firstWhere('feature', FeatureAccess::ROUTE_FOR_SIGNATURES)->updated_by)->toBe(1);

        $component->call('revokeFeature', FeatureAccess::ROUTE_FOR_SIGNATURES, '2');

        expect(app(FeatureAccess::class)->userIds(FeatureAccess::ROUTE_FOR_SIGNATURES))->toBe([]);
    });

    it('leaves people already picked out of the search', function () {
        access()->setMode(FeatureAccess::ROUTE_FOR_SIGNATURES, FeatureAccess::SELECTED);
        access()->grant(FeatureAccess::ROUTE_FOR_SIGNATURES, 2);
        $this->actingAs($this->admin);

        $feature = collect(Livewire::test(SignatureLauncher::class)
            ->call('loadRequests')
            ->set('featureSearch.'.FeatureAccess::ROUTE_FOR_SIGNATURES, 'example.test')
            ->instance()->featureAccess)->firstWhere('key', FeatureAccess::ROUTE_FOR_SIGNATURES);

        expect(array_column($feature['people'], 'id'))->toBe(['2'])
            ->and(array_column($feature['matches'], 'id'))->toEqualCanonicalizing(['1', '3']);
    });

    it('refuses every write from anyone but a super_admin', function () {
        $this->actingAs($this->other);

        Livewire::test(SignatureLauncher::class)
            ->call('setFeatureMode', FeatureAccess::ROUTE_FOR_SIGNATURES, FeatureAccess::SUPER_ADMINS)
            ->call('grantFeature', FeatureAccess::ROUTE_FOR_SIGNATURES, '3')
            ->call('saveLauncherPlacement', 'top-left', 10, 10);

        expect(FeatureRule::count())->toBe(0)
            ->and(UserPreference::count())->toBe(0)
            ->and(FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES, $this->other))->toBeTrue();
    });

    it('ignores a person who does not exist and a feature it does not know', function () {
        $this->actingAs($this->admin);

        Livewire::test(SignatureLauncher::class)
            ->call('grantFeature', FeatureAccess::ROUTE_FOR_SIGNATURES, '999')
            ->call('setFeatureMode', 'something-else', FeatureAccess::SELECTED)
            ->call('setFeatureMode', FeatureAccess::ROUTE_FOR_SIGNATURES, 'sometimes');

        expect(FeatureRule::count())->toBe(0);
    });

    it('still saves a super_admin\'s own button placement', function () {
        $this->actingAs($this->admin);

        Livewire::test(SignatureLauncher::class)->call('saveLauncherPlacement', 'top-left', 10, 10);

        expect(UserPreference::for(1)['launcher']['position'])->toBe('top-left');
    });
});
