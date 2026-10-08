<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Client\HubWebhookHandler;
use Kukux\DigitalSignature\Models\HubEvent;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * Events from the hub: signed with the shared secret, fresh, handled once.
 */
uses(ClientTestCase::class);

beforeEach(function () {
    $this->setUpClient();
    $this->user = $this->linkedUser();
});

function pendingSign(int $userId, array $attributes = []): HubPendingSign
{
    return HubPendingSign::create($attributes + [
        'hub_request_id'  => '11111111-aaaa-4aaa-8aaa-111111111111',
        'agent_job_uuid'  => '22222222-bbbb-4bbb-8bbb-222222222222',
        'user_id'         => $userId,
        'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        'document_hash'   => str_repeat('d', 64),
        'source_hash'     => str_repeat('s', 64),
        'specimen_hash'   => str_repeat('a', 64),
        'status'          => 'pending',
    ]);
}

describe('webhook receiver', function () {

    it('refuses a bad signature', function () {
        $this->webhook('person.separated', ['sub' => 'p-juan'], secret: 'wrong')
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_signature']);

        expect(HubEvent::query()->count())->toBe(0);
    });

    it('refuses a stale timestamp, even correctly signed', function () {
        $this->webhook('person.separated', ['sub' => 'p-juan'], timestamp: now()->subMinutes(10)->getTimestamp())
            ->assertStatus(401)
            ->assertJson(['error' => 'stale_timestamp']);
    });

    it('needs no CSRF token or session', function () {
        $this->webhook('signature.flagged', ['sub' => 'p-juan', 'document_hashes' => [], 'window' => ['from' => 'a', 'to' => 'b']])
            ->assertOk()
            ->assertJson(['received' => true]);
    });

    it('treats a replayed event id as a no-op', function () {
        $mirror = $this->mirror($this->user->id);
        $this->webhook('signature.revoked', ['sub' => 'p-juan', 'uuid' => $mirror->hub_uuid, 'reason' => 'lost'], id: 'evt-1')->assertOk();

        // Restore it, then replay: nothing happens the second time.
        $mirror->refresh()->update(['status' => 'active']);
        $this->webhook('signature.revoked', ['sub' => 'p-juan', 'uuid' => $mirror->hub_uuid], id: 'evt-1')
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        expect($mirror->fresh()->status)->toBe('active')
            ->and(HubEvent::query()->where('event_id', 'evt-1')->count())->toBe(1);
    });

    it('releases the event id when handling fails, so the hub retries', function () {
        $this->app->instance(HubWebhookHandler::class, Mockery::mock(HubWebhookHandler::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
        }));

        $this->webhook('signature.updated', ['sub' => 'p-juan'], id: 'evt-2')->assertStatus(500);

        expect(HubEvent::query()->where('event_id', 'evt-2')->exists())->toBeFalse();
    });

    it('stores the CMS on sign_request.completed', function () {
        $pending = pendingSign($this->user->id);

        $this->webhook('sign_request.completed', [
            'id' => $pending->hub_request_id, 'sub' => 'p-juan', 'status' => 'signed', 'cms' => base64_encode('der-bytes'),
        ])->assertOk();

        expect($pending->fresh()->status)->toBe('signed')
            ->and($pending->fresh()->cms)->toBe(base64_encode('der-bytes'));
    });

    it('records a declined sign request', function () {
        $pending = pendingSign($this->user->id);

        $this->webhook('sign_request.completed', [
            'id' => $pending->hub_request_id, 'sub' => 'p-juan', 'status' => 'declined', 'refusal_reason' => 'os_prompt_cancelled',
        ])->assertOk();

        expect($pending->fresh()->status)->toBe('declined')
            ->and(SignatureAudit::query()->where('event', SignatureAudit::HUB_SIGN_DECLINED)->exists())->toBeTrue();
    });

    it('re-pulls the mirror on signature.updated', function () {
        $png = stampablePng(150, 40);
        $this->mirror($this->user->id);
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png, 'dddddddd-4444-4444-8444-444444444444'));

        $this->webhook('signature.updated', ['sub' => 'p-juan', 'uuid' => 'dddddddd-4444-4444-8444-444444444444', 'image_sha256' => hash('sha256', $png)])
            ->assertOk();

        expect(app(\Kukux\DigitalSignature\Client\HubSignatureSync::class)->mirrorFor($this->user->id)->hub_image_hash)
            ->toBe(hash('sha256', $png));
    });

    it('revokes the mirror and blocks pending requests on signature.revoked and person.separated', function (string $event, array $data) {
        $mirror = $this->mirror($this->user->id);
        $pending = pendingSign($this->user->id, ['status' => 'signed', 'cms' => 'x']);

        $this->webhook($event, $data + ['uuid' => $mirror->hub_uuid])->assertOk();

        expect($mirror->fresh()->status)->toBe('revoked')
            ->and($pending->fresh()->status)->toBe('refused');
        Storage::disk('testing')->assertMissing($mirror->image_path);
    })->with([
        'revoked'   => ['signature.revoked', ['sub' => 'p-juan', 'reason' => 'fraud']],
        'separated' => ['person.separated', ['sub' => 'p-juan']],
    ]);

    it('ignores a completed request after the signer was revoked', function () {
        $pending = pendingSign($this->user->id, ['status' => 'refused', 'reason' => 'fraud']);

        $this->webhook('sign_request.completed', ['id' => $pending->hub_request_id, 'status' => 'signed', 'cms' => 'eA=='])->assertOk();

        expect($pending->fresh()->status)->toBe('refused');
    });

    it('logs and audits signature.flagged with the local signatures it names', function () {
        pendingSign($this->user->id, ['status' => 'done', 'document_hash' => str_repeat('f', 64), 'payload' => ['signature_id' => 77]]);

        $this->webhook('signature.flagged', [
            'sub' => 'p-juan', 'document_hashes' => [str_repeat('f', 64)], 'window' => ['from' => '2026-10-01', 'to' => '2026-10-02'],
        ])->assertOk();

        $audit = SignatureAudit::query()->where('event', HubWebhookHandler::FLAGGED)->first();

        expect($audit)->not->toBeNull()
            ->and($audit->subject_user_id)->toBe($this->user->id)
            ->and($audit->context['signature_ids'])->toBe([77]);
    });
});
