<?php

use Kukux\DigitalSignature\Client\Exceptions\SignatureManagedAtHubException;
use Kukux\DigitalSignature\Filament\Concerns\RegistersSignatures;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * Nothing registers or revokes a person's signature in a client app: that
 * happens at the hub, and the mirror follows.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

describe('client mode guards', function () {

    it('refuses SignatureManager::store()', function () {
        $user = $this->linkedUser();

        expect(fn () => app(SignatureManager::class)->store($user->id, fakePng(), 'draw', certificatePassword: 'secret'))
            ->toThrow(SignatureManagedAtHubException::class);

        expect(Signature::query()->count())->toBe(0);
    });

    it('refuses registerSignature() with a notification, and canRegisterSignature() is false', function () {
        $this->actingAs($this->linkedUser());

        $surface = new class {
            use RegistersSignatures;

            public array $failures = [];

            public function register(): ?Signature
            {
                return $this->registerSignature(fakePng(), 'secret');
            }

            public function can(): bool
            {
                return $this->canRegisterSignature();
            }

            protected function signatureFailure(string $title, string $body): void
            {
                $this->failures[] = $title;
            }
        };

        expect($surface->register())->toBeNull()
            ->and($surface->failures)->toBe(['Managed at UPLB Signature'])
            ->and($surface->can())->toBeFalse()
            ->and(Signature::query()->count())->toBe(0);
    });

    it('refuses revoking a mirror locally', function () {
        $user = $this->linkedUser();
        $mirror = $this->mirror($user->id);

        expect(fn () => app(SignatureManager::class)->revoke($mirror))
            ->toThrow(SignatureManagedAtHubException::class);

        expect($mirror->fresh()->status)->toBe('active');
    });
});
