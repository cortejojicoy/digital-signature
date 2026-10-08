<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Client\Exceptions\MirrorIntegrityException;
use Kukux\DigitalSignature\Client\HubSignatureSync;
use Kukux\DigitalSignature\Filament\Livewire\SignatureLauncher;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * The mirror: a read-only copy of the hub's image, written only by
 * HubSignatureSync, checked against the hub's hash before it is kept.
 */
uses(ClientTestCase::class);

beforeEach(function () {
    $this->setUpClient();
    $this->user = $this->linkedUser();
    $this->sync = app(HubSignatureSync::class);
});

describe('HubSignatureSync::pull', function () {

    it('writes the image, then a mirror row pointing at it', function () {
        $png = stampablePng();
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png));

        $mirror = $this->sync->pull($this->user);

        expect($mirror)->not->toBeNull()
            ->and($mirror->source)->toBe('hub')
            ->and($mirror->signable_id)->toBeNull()
            ->and($mirror->status)->toBe('active')
            ->and($mirror->hub_uuid)->toBe('aaaaaaaa-1111-4111-8111-111111111111')
            ->and($mirror->hub_image_hash)->toBe(hash('sha256', $png))
            ->and($mirror->image_path)->toBe('signatures/hub/aaaaaaaa-1111-4111-8111-111111111111.png')
            ->and($mirror->getCertificatePassword())->toBeNull()
            ->and($mirror->hub_synced_at)->not->toBeNull()
            ->and(Storage::disk('testing')->get($mirror->image_path))->toBe($png);
    });

    it('asks with If-None-Match and only touches the check time on 304', function () {
        $png = stampablePng();
        $mirror = $this->mirror($this->user->id, $png);
        $mirror->update(['hub_synced_at' => now()->subDays(3)]);
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png));

        $pulled = $this->sync->pull($this->user);

        expect($pulled->id)->toBe($mirror->id)
            ->and($pulled->hub_synced_at->isToday())->toBeTrue()
            ->and(Signature::query()->where('source', 'hub')->count())->toBe(1);
        Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"'.hash('sha256', $png).'"'));
    });

    it('rejects an image that does not match its hash', function () {
        $this->fakeHub($this->hubSignatureRoutes('p-juan', stampablePng(), sha: str_repeat('0', 64)));

        expect(fn () => $this->sync->pull($this->user))->toThrow(MirrorIntegrityException::class);
        expect(Signature::query()->count())->toBe(0)
            ->and(Storage::disk('testing')->allFiles())->toBe([]);
    });

    it('replaces a changed specimen in place and deletes the old file', function () {
        $old = $this->mirror($this->user->id, stampablePng(100, 30), 'bbbbbbbb-2222-4222-8222-222222222222');
        $new = stampablePng(140, 50);
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $new, 'cccccccc-3333-4333-8333-333333333333'));

        $pulled = $this->sync->pull($this->user);

        expect($pulled->id)->toBe($old->id)
            ->and($pulled->hub_image_hash)->toBe(hash('sha256', $new))
            ->and($pulled->image_path)->toBe('signatures/hub/cccccccc-3333-4333-8333-333333333333.png');
        Storage::disk('testing')->assertMissing('signatures/hub/bbbbbbbb-2222-4222-8222-222222222222.png');
        Storage::disk('testing')->assertExists($pulled->image_path);
    });

    it('clears the mirror when the hub has no signature (404)', function () {
        $mirror = $this->mirror($this->user->id);
        $this->fakeHub(['hub.test/signature/hub/api/v1/people/p-juan/signature' => Http::response(['error' => 'no_signature', 'message' => 'None.'], 404)]);

        expect($this->sync->pull($this->user))->toBeNull()
            ->and($mirror->fresh()->status)->toBe('revoked');
        Storage::disk('testing')->assertMissing($mirror->image_path);
    });

    it('does nothing for a user never linked to the hub', function () {
        Http::fake();

        expect($this->sync->pull(makeUser(50, 'Not Linked')))->toBeNull();
        Http::assertNothingSent();
    });
});

describe('HubSignatureSync::revoke', function () {

    it('deletes the image, revokes the mirror, blocks pending requests and empties the tray', function () {
        $mirror = $this->mirror($this->user->id);
        $pending = HubPendingSign::create([
            'user_id' => $this->user->id, 'idempotency_key' => 'k1', 'document_hash' => str_repeat('d', 64),
            'specimen_hash' => $mirror->hub_image_hash, 'status' => 'pending',
        ]);

        expect($this->sync->revoke('p-juan', $mirror->hub_uuid, 'fraud'))->toBe(1);

        expect($mirror->fresh()->status)->toBe('revoked')
            ->and($pending->fresh()->status)->toBe('refused')
            ->and($pending->fresh()->reason)->toBe('fraud');
        Storage::disk('testing')->assertMissing($mirror->image_path);

        $this->actingAs($this->user);
        expect((new SignatureLauncher)->getSignaturesProperty())->toHaveCount(0);
    });

    it('never touches rows copied onto documents', function () {
        $mirror = $this->mirror($this->user->id);
        $onDocument = Signature::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->user->id,
            'image_path' => $mirror->image_path, 'image_hash' => $mirror->image_hash, 'source' => 'hub',
            'status' => 'signed', 'signable_type' => 'App\\Doc', 'signable_id' => 7,
        ]);

        $this->sync->revoke('p-juan');

        expect($onDocument->fresh()->status)->toBe('signed');
    });
});

describe('HubSignatureSync::staleCheck', function () {

    it('re-pulls a mirror older than stale_after, and leaves a fresh one alone', function () {
        $png = stampablePng();
        $mirror = $this->mirror($this->user->id, $png);
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png));

        $this->sync->staleCheck($this->user);
        Http::assertNothingSent();

        $mirror->update(['hub_synced_at' => now()->subDays(2)]);
        $this->sync->staleCheck($this->user);

        expect($mirror->fresh()->hub_synced_at->isToday())->toBeTrue();
    });

    it('keeps the mirror when the hub is down', function () {
        $mirror = $this->mirror($this->user->id);
        $mirror->update(['hub_synced_at' => now()->subDays(2)]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        expect($this->sync->staleCheck($this->user)?->id)->toBe($mirror->id);
    });
});
