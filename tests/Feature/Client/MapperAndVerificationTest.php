<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Signatories\HubUserMapper;
use Kukux\DigitalSignature\Signatories\RelationUserMapper;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * Tagged people are hub people (A8); a certificate's standing is the hub's
 * (A9); a mirror's image is served from the mirror disk.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

/** A host's personnel row, not a login. */
function personnelRow(array $attributes): Model
{
    return (new class extends Model {
        protected $guarded = [];
    })->forceFill($attributes);
}

describe('HubUserMapper', function () {

    it('passes a login through', function () {
        $user = makeUser(31, 'Login User');

        expect(app(HubUserMapper::class)->toUser($user))->toBe($user);
    });

    it('maps a tagged person to the local user linked to their hub sub, and links the app as holder', function () {
        $this->linkedUser(32, 'p-ana', 'Ana Reyes');
        $this->fakeHub([
            'hub.test/signature/hub/api/v1/people/p-ana/link' => Http::response(['linked' => true]),
            'hub.test/signature/hub/api/v1/people*'          => Http::response(['data' => [['sub' => 'p-ana']]]),
        ]);

        $user = app(HubUserMapper::class)->toUser(personnelRow(['employee_number' => 'E-32', 'email' => 'ana@up.edu.ph']));

        expect($user?->getKey())->toBe(32);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'people?emp_no=E-32'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/people/p-ana/link'));
    });

    it('returns null (tagged without a login) for a hub person who never signed in here', function () {
        $this->fakeHub([
            'hub.test/signature/hub/api/v1/people/p-new/link' => Http::response(['linked' => true]),
            'hub.test/signature/hub/api/v1/people*'          => Http::response(['data' => [['sub' => 'p-new']]]),
        ]);

        expect(app(HubUserMapper::class)->toUser(personnelRow(['email' => 'new@up.edu.ph'])))->toBeNull();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/people/p-new/link'));
    });

    it('routes as unassigned rather than failing when the hub is down', function () {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        expect(app(HubUserMapper::class)->toUser(personnelRow(['email' => 'x@up.edu.ph'])))->toBeNull();
    });

    it('leaves a host-bound mapper alone', function () {
        app()->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
        (new \Kukux\DigitalSignature\Client\ClientServiceProvider(app()))->boot();

        expect(app(SignatoryUserMapper::class))->toBeInstanceOf(RelationUserMapper::class);
    });
});

describe('verification in client mode', function () {

    function signedRow(int $userId, ?string $fingerprint = 'f1'): Signature
    {
        return Signature::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'image_path' => 'x.png', 'image_hash' => 'h',
            'source' => 'hub', 'status' => 'signed', 'signable_type' => 'App\\Doc', 'signable_id' => 1,
            'certificate_fingerprint' => $fingerprint, 'signed_at' => now(),
        ]);
    }

    it('asks the hub about the certificate, and caches the answer', function () {
        $sig = signedRow(makeUser(41, 'Signer')->id);
        $this->fakeHub(['hub.test/signature/hub/api/v1/certificates/f1' => Http::response(['fingerprint' => 'f1', 'status' => 'valid'])]);

        $this->getJson(route('signature.verify', $sig->uuid))->assertOk()->assertJson(['valid' => true, 'certificate' => 'valid', 'signer' => 'Signer']);
        $this->getJson(route('signature.verify', $sig->uuid))->assertOk();

        Http::assertSentCount(2); // token + one certificate lookup
    });

    it('answers "not valid", in the same shape, for a certificate the hub revoked', function () {
        $sig = signedRow(makeUser(42, 'Signer')->id);
        $this->fakeHub(['hub.test/signature/hub/api/v1/certificates/f1' => Http::response(['fingerprint' => 'f1', 'status' => 'revoked'])]);

        $this->getJson(route('signature.verify', $sig->uuid))
            ->assertNotFound()
            ->assertExactJson(['valid' => false, 'reference' => substr($sig->uuid, 0, 8), 'signer' => null, 'role' => null, 'signedAt' => null, 'complete' => null]);
    });

    it('keeps the local answer when the hub is down', function () {
        $sig = signedRow(makeUser(43, 'Signer')->id);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->getJson(route('signature.verify', $sig->uuid))->assertOk()->assertJson(['valid' => true, 'certificate' => 'unchecked']);
    });
});

describe('mirror images', function () {

    it('are served from the mirror disk through the signed route only', function () {
        Storage::fake('rustfs');
        config()->set('signature.hub.mirror_disk', 'rustfs');
        $user = $this->linkedUser();
        $png = stampablePng();
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png));

        $mirror = app(\Kukux\DigitalSignature\Client\HubSignatureSync::class)->pull($user);

        Storage::disk('rustfs')->assertExists($mirror->image_path);
        Storage::disk('testing')->assertMissing($mirror->image_path);

        $url = $mirror->getTemporaryImageUrl();
        expect($url)->toContain('/signature/assets/');

        expect($this->get($url)->assertOk()->streamedContent())->toBe($png);
    });
});
