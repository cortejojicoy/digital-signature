<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\DeviceRegistered;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Notifications\NewSigningDeviceNotification;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Kukux\DigitalSignature\Security\EcdsaSignature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Tests\Support\TestUser;

const MAC_CHROME_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * A stand-in for one browser's Web Crypto key: a P-256 key whose signatures
 * are converted to raw r‖s, exactly as crypto.subtle.sign returns them.
 */
function browserKey(): array
{
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $spki = preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($private)['key']);

    return ['private' => $private, 'spki' => $spki, 'fingerprint' => hash('sha256', base64_decode($spki))];
}

function signRaw(array $key, string $message): string
{
    openssl_sign($message, $der, $key['private'], OPENSSL_ALGO_SHA256);

    return base64_encode(EcdsaSignature::derToRaw($der));
}

/**
 * What deviceAttestation.js does: fetch a challenge, sign it, post the proof.
 */
function attestBrowser($test, array $key, ?string $nonce = null): \Illuminate\Testing\TestResponse
{
    $challenge = $test->withHeader('User-Agent', MAC_CHROME_UA)
        ->postJson(route('signature.devices.challenge'))
        ->assertOk()
        ->json();

    $nonce ??= $challenge['nonce'];
    $message = DeviceProofVerifier::message('attest', $nonce, $challenge['user_id'], $key['fingerprint']);

    return $test->withHeader('User-Agent', MAC_CHROME_UA)->postJson(route('signature.devices.attest'), [
        'public_key' => $key['spki'],
        'signature'  => signRaw($key, $message),
        'nonce'      => $nonce,
        'hints'      => ['touch' => false],
    ]);
}

function signingUser(int $id = 1): TestUser
{
    $user = TestUser::create(['id' => $id, 'name' => "User {$id}", 'email' => "user{$id}@example.test"]);
    Auth::login($user);

    return $user;
}

function deviceDocument(): Signable
{
    Storage::disk('testing')->put('documents/contract.pdf', minimalPdf());

    return new class extends Model implements Signable
    {
        protected $table = 'contracts';

        public function getSignableTitle(): string
        {
            return 'Contract';
        }

        public function getSignablePdfPath(): string
        {
            return 'documents/contract.pdf';
        }

        public function getSignableId(): int|string
        {
            return 7;
        }
    };
}

/** Rebind the request-scoped registry, as a new request would. */
function freshRegistry(): DeviceRegistry
{
    app()->forgetScopedInstances();

    return app(DeviceRegistry::class);
}

describe('device attestation endpoints', function () {

    beforeEach(fn () => Storage::fake('testing'));

    it('requires a signed-in user', function () {
        $this->postJson(route('signature.devices.challenge'))->assertUnauthorized();
    });

    it('accepts a valid proof and reports an unregistered key', function () {
        signingUser();
        $key = browserKey();

        attestBrowser($this, $key)
            ->assertOk()
            ->assertJson(['status' => 'unregistered', 'fingerprint' => $key['fingerprint'], 'device' => null]);

        // Proving a key does not register it; signing does.
        expect(SigningDevice::count())->toBe(0);
    });

    it('refuses a replayed challenge', function () {
        signingUser();
        $key = browserKey();

        $first = $this->postJson(route('signature.devices.challenge'))->json('nonce');
        $message = DeviceProofVerifier::message('attest', $first, 1, $key['fingerprint']);
        $body = ['public_key' => $key['spki'], 'signature' => signRaw($key, $message), 'nonce' => $first];

        $this->postJson(route('signature.devices.attest'), $body)->assertOk();
        $this->postJson(route('signature.devices.attest'), $body)->assertStatus(422);
    });

    it('refuses a proof signed by a different key than the one presented', function () {
        signingUser();
        $presented = browserKey();
        $other = browserKey();

        $nonce = $this->postJson(route('signature.devices.challenge'))->json('nonce');
        $message = DeviceProofVerifier::message('attest', $nonce, 1, $presented['fingerprint']);

        $this->postJson(route('signature.devices.attest'), [
            'public_key' => $presented['spki'],
            'signature'  => signRaw($other, $message),
            'nonce'      => $nonce,
        ])->assertStatus(422);
    });

    it('refuses a challenge issued to another user', function () {
        signingUser(1);
        $nonce = $this->postJson(route('signature.devices.challenge'))->json('nonce');

        $second = TestUser::create(['id' => 2, 'name' => 'Two', 'email' => 'two@example.test']);
        Auth::login($second);
        $key = browserKey();

        $this->postJson(route('signature.devices.attest'), [
            'public_key' => $key['spki'],
            'signature'  => signRaw($key, DeviceProofVerifier::message('attest', $nonce, 2, $key['fingerprint'])),
            'nonce'      => $nonce,
        ])->assertStatus(422);
    });

    it('answers 409 for a revoked key so the browser can start over', function () {
        $user = signingUser();
        $key = browserKey();
        attestBrowser($this, $key);
        $device = freshRegistry()->forSigning($user->id);

        app(DeviceRegistry::class)->revoke($device);

        attestBrowser($this, $key)->assertStatus(409)->assertJson(['status' => 'revoked']);
    });

    it('renames and revokes only the user\'s own devices', function () {
        $user = signingUser();
        attestBrowser($this, browserKey());
        $device = freshRegistry()->forSigning($user->id);

        $this->patchJson(route('signature.devices.update', $device->uuid), ['label' => '<b>Work</b> laptop'])
            ->assertOk()
            ->assertJsonPath('device.label', 'Work laptop');

        Auth::login(TestUser::create(['id' => 2, 'name' => 'Two', 'email' => 'two@example.test']));

        $this->deleteJson(route('signature.devices.destroy', $device->uuid))->assertNotFound();
        expect($device->refresh()->status)->toBe('active');
    });
});

describe('recording the device on signatures', function () {

    beforeEach(function () {
        Storage::fake('testing');
        Notification::fake();
    });

    it('registers the device when a signature is created on it', function () {
        Event::fake([DeviceRegistered::class]);
        $user = signingUser();
        attestBrowser($this, browserKey())->assertOk();

        freshRegistry();
        app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        $signature = Signature::firstOrFail();
        $device = SigningDevice::firstOrFail();

        expect($signature->device_id)->toBe($device->id)
            ->and($device->label)->toBe('Chrome on Mac')
            ->and($device->device_type)->toBe('desktop')
            ->and($device->status)->toBe('active');

        Event::assertDispatched(DeviceRegistered::class);
        // The first device is the normal case of starting out, not an alert.
        Notification::assertNothingSent();
    });

    it('records the device a signature is used on, not the one it was created on', function () {
        $user = signingUser();

        attestBrowser($this, browserKey());
        freshRegistry();
        $primary = app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        // Later, from a phone.
        attestBrowser($this, browserKey());
        freshRegistry();
        $used = app(SignatureManager::class)->storeForDocument($primary, $user->id, deviceDocument());

        expect($used->device_id)->not->toBeNull()
            ->and($used->device_id)->not->toBe($primary->device_id)
            ->and(SigningDevice::count())->toBe(2);

        Notification::assertSentTo($user, NewSigningDeviceNotification::class);
    });

    it('records no device for delegated signing, whoever holds the session', function () {
        $user = signingUser();
        attestBrowser($this, browserKey());
        freshRegistry();
        $primary = app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        $delegated = app(SignatureManager::class)->storeDelegated($primary, deviceDocument());

        expect($delegated->device_id)->toBeNull()
            ->and($delegated->source)->toBe('auto');
    });

    it('signs without a device when none is required', function () {
        $user = signingUser();

        $signature = app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        expect($signature->device_id)->toBeNull();
    });

    it('refuses to sign without a verified device under enforce', function () {
        config(['signature.devices.require' => 'enforce']);
        $user = signingUser();

        expect(fn () => app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        ))->toThrow(UnregisteredDeviceException::class);

        // Refused before anything was written.
        expect(Signature::count())->toBe(0)
            ->and(Storage::disk('testing')->allFiles())->toBe([]);
    });

    it('treats an expired proof as no device', function () {
        config(['signature.devices.require' => 'enforce']);
        $user = signingUser();
        attestBrowser($this, browserKey());

        $this->travel(config('signature.devices.attestation_ttl') + 1)->seconds();

        expect(fn () => freshRegistry()->forSigning($user->id))->toThrow(UnregisteredDeviceException::class);
    });

    it('stops a revoked device from signing even when devices are optional', function () {
        $user = signingUser();
        $key = browserKey();
        attestBrowser($this, $key);
        $device = freshRegistry()->forSigning($user->id);

        // Revoked elsewhere, while this session still holds a fresh proof.
        $device->update(['status' => 'revoked', 'revoked_at' => now()]);

        expect(fn () => freshRegistry()->forSigning($user->id))->toThrow(UnregisteredDeviceException::class);
    });

    it('limits a signature to its creation device under creation_device_only', function () {
        config(['signature.devices.usage_policy' => 'creation_device_only']);
        $user = signingUser();

        $laptop = browserKey();
        attestBrowser($this, $laptop);
        freshRegistry();
        $primary = app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        attestBrowser($this, browserKey());
        expect(fn () => freshRegistry()->forSigning($user->id, $primary))->toThrow(MachineBindingException::class);

        attestBrowser($this, $laptop);
        expect(freshRegistry()->forSigning($user->id, $primary)?->id)->toBe($primary->device_id);
    });

    it('caps the number of devices per user', function () {
        config(['signature.devices.max_per_user' => 1]);
        $user = signingUser();

        attestBrowser($this, browserKey());
        freshRegistry()->forSigning($user->id);

        attestBrowser($this, browserKey());
        expect(fn () => freshRegistry()->forSigning($user->id))->toThrow(UnregisteredDeviceException::class);
    });

    it('stamps the device on audit rows written by the signer', function () {
        $user = signingUser();
        attestBrowser($this, browserKey());
        $device = freshRegistry()->forSigning($user->id);

        $audit = SignatureAudit::record(SignatureAudit::REQUEST_SIGNED, ['subject_user_id' => $user->id]);

        expect($audit->device_id)->toBe($device->id);
    });

    it('does nothing at all when the feature is disabled', function () {
        config(['signature.devices.enabled' => false, 'signature.devices.require' => 'enforce']);
        $user = signingUser();

        $signature = app(SignatureManager::class)->store(
            userId: $user->id, input: fakePng(), source: 'draw', certificatePassword: 'secret',
        );

        expect($signature->device_id)->toBeNull();
    });
});

describe('showing the device', function () {

    it('summarises where a signature was created and used, surviving a vanished signable class', function () {
        $user = signingUser();
        attestBrowser($this, browserKey());
        $device = freshRegistry()->forSigning($user->id);
        $device->update(['label' => 'Work laptop']);

        $primary = makePrimarySignature($user->id);
        $primary->update(['device_id' => $device->id]);

        // A use whose model class the host has since renamed.
        Signature::create([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'user_id'       => $user->id,
            'image_path'    => 'x.png',
            'image_hash'    => $primary->image_hash,
            'signable_type' => 'App\\Models\\RenamedContract',
            'signable_id'   => 5,
            'status'        => 'signed',
            'device_id'     => $device->id,
            'signed_at'     => now(),
        ]);

        expect($primary->fresh()->deviceSummary())->toBe('Work laptop · Browser key')
            ->and($primary->documentUseSummaries())->toHaveCount(1)
            ->and($primary->documentUseSummaries()[0])->toStartWith('RenamedContract #5 · Work laptop · Browser key · ');
    });

    it('says why there is no device', function () {
        $user = signingUser();

        $manual = makePrimarySignature($user->id);
        $auto = makePrimarySignature($user->id);
        $auto->update(['source' => 'auto']);

        expect($manual->deviceSummary())->toBe('No verified device')
            ->and($auto->deviceSummary())->toBe('Applied automatically');
    });
});
