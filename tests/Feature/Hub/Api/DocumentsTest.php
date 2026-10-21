<?php

use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\HubDocument;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The hub's half of verifying a PDF from any app.
 *
 * Apps report each routed document (PUT documents/{uuid}); the hub's public
 * lookup then answers for those fingerprints the way the issuing app's own
 * verify page would, naming that app as the issuer.
 */
uses(HubApiEnvironment::class);

beforeEach(function () {
    $this->setUpHubApi();

    $registered = $this->registerApp('performance');
    $this->hubApp = $registered['app'];
    $this->headers = $this->bearer($this->appToken($this->hubApp));

    $this->sha = fn (string $what) => hash('sha256', $what);
    $this->uuid = (string) Str::uuid();

    // A two-slot document, both signed: versions 0 (base), 1 and 2.
    $this->report = fn (array $overrides = []) => array_replace([
        'title'    => 'Accomplishment Report',
        'status'   => 'complete',
        'expired'  => false,
        'required' => 2,
        'versions' => [
            ['number' => 0, 'sha256' => ($this->sha)('base'), 'created_at' => '2026-10-01T08:00:00+08:00'],
            ['number' => 1, 'sha256' => ($this->sha)('v1'), 'created_at' => '2026-10-02T08:00:00+08:00'],
            ['number' => 2, 'sha256' => ($this->sha)('v2'), 'created_at' => '2026-10-03T08:00:00+08:00'],
        ],
        'slots' => [
            ['slot' => 'prepared_by', 'label' => 'Prepared by', 'state' => 'signed', 'signer' => 'Ana Prepared',
                'signed_at' => '2026-10-02T08:00:00+08:00', 'version' => 1, 'certificate_fingerprint' => str_repeat('a', 64), 'revoked' => false],
            ['slot' => 'accepted_by', 'label' => 'Accepted by', 'state' => 'signed', 'signer' => 'Ben Accepted',
                'signed_at' => '2026-10-03T08:00:00+08:00', 'version' => 2, 'certificate_fingerprint' => str_repeat('b', 64), 'revoked' => false],
        ],
    ], $overrides);

    $this->put = fn (array $body, ?string $uuid = null, ?array $headers = null) => $this->putJson(
        "/signature/hub/api/v1/documents/".($uuid ?? $this->uuid),
        $body,
        $headers ?? $this->headers,
    );

    $this->lookup = fn (string $sha256, ?string $revision = null) => $this->postJson('/signature/verify/lookup', array_filter([
        'sha256' => $sha256, 'signed_revision_sha256' => $revision,
    ]));
});

describe('reporting a document', function () {

    it('keeps an app\'s report and its versions\' fingerprints', function () {
        ($this->put)(($this->report)())
            ->assertOk()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('versions', 3);

        $document = HubDocument::query()->sole();

        expect($document->app_id)->toBe($this->hubApp->id)
            ->and($document->title)->toBe('Accomplishment Report')
            ->and($document->versions->pluck('sha256')->all())->toBe([($this->sha)('base'), ($this->sha)('v1'), ($this->sha)('v2')]);
    });

    it('replaces the document on every report, so retries and updates are harmless', function () {
        ($this->put)(($this->report)(['status' => 'open', 'versions' => [
            ['number' => 0, 'sha256' => ($this->sha)('base')],
        ], 'slots' => []]))->assertOk();

        ($this->put)(($this->report)())->assertOk();
        ($this->put)(($this->report)())->assertOk();

        expect(HubDocument::query()->count())->toBe(1)
            ->and(HubDocument::query()->sole()->versions()->count())->toBe(3)
            ->and(HubDocument::query()->sole()->status)->toBe('complete');
    });

    it('needs an app token that can ask for signatures', function () {
        $readOnly = $this->registerApp('directory-only', ['signatures.read'])['app'];

        ($this->put)(($this->report)(), headers: $this->bearer($this->appToken($readOnly)))->assertForbidden();
        ($this->put)(($this->report)(), headers: ['Accept' => 'application/json'])->assertUnauthorized();
    });

    it('refuses a report that does not hold together', function (array $bad) {
        ($this->put)(array_replace(($this->report)(), $bad))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_request');
    })->with([
        'no versions'       => [['versions' => []]],
        'a bad fingerprint' => [['versions' => [['number' => 0, 'sha256' => 'nope']]]],
        'an unknown status' => [['status' => 'shredded']],
        'an unknown state'  => [['slots' => [['slot' => 'x', 'label' => 'X', 'state' => 'forged']]]],
    ]);
});

describe('verifying at the hub', function () {

    beforeEach(fn () => ($this->put)(($this->report)())->assertOk());

    it('confirms the final copy and names the app that issued it', function () {
        $response = ($this->lookup)(($this->sha)('v2'))
            ->assertOk()
            ->assertJsonPath('verdict', 'complete')
            ->assertJsonPath('issuer', 'Performance')
            ->assertJsonPath('document.title', 'Accomplishment Report')
            ->assertJsonPath('document.signed', 2);

        expect(collect($response->json('slots'))->pluck('signer')->all())->toBe(['Ana Prepared', 'Ben Accepted']);
    });

    it('shows an earlier copy as superseded, without the later signer', function () {
        $response = ($this->lookup)(($this->sha)('v1'))->assertJsonPath('verdict', 'superseded');

        expect(collect($response->json('slots'))->firstWhere('slot', 'accepted_by'))
            ->state->toBe('signed_later')
            ->signer->toBeNull();
    });

    it('recognises the signed copy inside a file saved again since', function () {
        ($this->lookup)(($this->sha)('v2 + edits'), ($this->sha)('v2'))
            ->assertJsonPath('verdict', 'signature_valid_file_changed');
    });

    it('says not issued for fingerprints no app reported', function () {
        ($this->lookup)(($this->sha)('made in Word'))->assertJsonPath('verdict', 'not_issued');
    });

    it('answers certificate status from the hub\'s own certificates', function () {
        $user = TestUser::create(['id' => 77, 'name' => 'Ana', 'email' => 'ana@up.edu.ph']);
        UserCertificate::create(['user_id' => $user->id, 'pfx_path' => 'certs/ana.pfx', 'fingerprint' => str_repeat('a', 64), 'revoked_at' => now()]);

        $slots = collect(($this->lookup)(($this->sha)('v2'))->json('slots'))->pluck('certificate', 'slot');

        expect($slots['prepared_by'])->toBe('revoked')
            // Not a certificate this hub issued: it can't vouch either way.
            ->and($slots['accepted_by'])->toBe('unknown');
    });

    it('does not let a second app take over another app\'s fingerprints', function () {
        // The issuer reported first; a later claim to the same bytes is a
        // different app trying to vouch for a file it didn't issue.
        $other = $this->registerApp('other-app')['app'];
        $this->travel(5)->minutes();

        ($this->put)(($this->report)(['slots' => [
            ['slot' => 'accepted_by', 'label' => 'Accepted by', 'state' => 'signed', 'signer' => 'Mallory', 'version' => 2],
        ]]), (string) Str::uuid(), $this->bearer($this->appToken($other)))->assertOk();

        ($this->lookup)(($this->sha)('v2'))
            ->assertJsonPath('issuer', 'Performance')
            ->assertJsonPath('slots.1.signer', 'Ben Accepted');
    });
});
