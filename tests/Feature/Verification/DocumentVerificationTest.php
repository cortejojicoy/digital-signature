<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\UserCertificate;
use Kukux\DigitalSignature\Services\DocumentRouter;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;
use Kukux\DigitalSignature\Verification\DocumentLookup;

/**
 * "Drop a PDF here": the record half of verifying a whole file.
 *
 * The browser sends fingerprints; these tests send the fingerprints the
 * package itself stored for each version, and check what comes back: which
 * version a file is, how far signing had got, and nothing a holder of that
 * copy couldn't already read off it.
 */
describe('document verification', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();
        stubSignatureEmbedding();

        $this->boss = signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);
        app()->instance(LeaveLedger::class, new LeaveLedger([22 => 3]));

        $this->form = app(DocumentRouter::class)->route('leave-form', $this->juana, ['period' => '2026-09'])->record();
        $this->session = $this->form->latestSigningSession();

        $this->signAs = function (string $slot, int $userId): Signature {
            $request = $this->session->requests()->where('slot_key', $slot)->first();

            return app(SigningSessionManager::class)->sign($request, $userId);
        };

        $this->lookup = fn (string $sha256, ?string $revision = null) => $this->postJson('/signature/verify/lookup', array_filter([
            'sha256'                 => $sha256,
            'signed_revision_sha256' => $revision,
        ]));

        $this->slot = fn ($response, string $key) => collect($response->json('slots'))->firstWhere('slot', $key);
    });

    afterEach(fn () => Mockery::close());

    it('says a file it has never seen was not issued here', function () {
        ($this->lookup)(hash('sha256', 'a PDF made in Word with pasted stamps'))
            ->assertOk()
            ->assertJsonPath('verdict', 'not_issued')
            ->assertJsonPath('document', null)
            ->assertJsonPath('slots', []);
    });

    it('recognises the frozen base as a genuine copy nobody has signed yet', function () {
        $response = ($this->lookup)($this->session->base_document_hash)
            ->assertOk()
            ->assertJsonPath('verdict', 'in_progress')
            ->assertJsonPath('document.version', 0)
            ->assertJsonPath('document.signed', 0)
            ->assertJsonPath('document.required', 2);

        expect(collect($response->json('slots'))->pluck('state')->all())->toBe(['pending', 'pending']);
    });

    it('shows a part-signed copy as genuine but unfinished, naming only who signed it', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);

        $response = ($this->lookup)($first->signed_document_hash)
            ->assertJsonPath('verdict', 'in_progress')
            ->assertJsonPath('document.version', 1)
            ->assertJsonPath('document.signed', 1);

        expect(($this->slot)($response, 'applicant'))
            ->state->toBe('signed')
            ->signer->toBe('Juana Cruz')
            ->label->toBe('Applicant')
            ->certificate->toBe('valid')
            ->and(($this->slot)($response, 'applicant')['signed_at'])->not->toBeNull()
            // Who it is still waiting on is not this copy's to say.
            ->and(($this->slot)($response, 'reviewer'))
            ->state->toBe('pending')
            ->signer->toBeNull();
    });

    it('confirms the last version of a finished document as the complete copy of record', function () {
        ($this->signAs)('applicant', $this->juana->user_id);
        $last = ($this->signAs)('reviewer', $this->boss->user_id);

        $response = ($this->lookup)($last->signed_document_hash)
            ->assertJsonPath('verdict', 'complete')
            ->assertJsonPath('document.version', 2)
            ->assertJsonPath('document.versions', 2)
            ->assertJsonPath('document.status', 'complete');

        expect(collect($response->json('slots'))->pluck('signer', 'slot')->all())
            ->toBe(['applicant' => 'Juana Cruz', 'reviewer' => 'Dr Reyes']);
    });

    it('marks an earlier copy as superseded, and never shows it carrying a later signature', function () {
        // The cheat this exists for: holding the 1-of-2 copy and claiming it's final.
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        ($this->signAs)('reviewer', $this->boss->user_id);

        $response = ($this->lookup)($first->signed_document_hash)
            ->assertJsonPath('verdict', 'superseded')
            ->assertJsonPath('document.version', 1)
            ->assertJsonPath('document.versions', 2);

        expect(($this->slot)($response, 'reviewer'))
            ->state->toBe('signed_later')
            ->signer->toBeNull();
    });

    it('recognises a signed copy saved again after signing by its signed prefix', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        ($this->signAs)('reviewer', $this->boss->user_id);
        $last = $this->session->fresh()->current_document_hash;

        ($this->lookup)(hash('sha256', 'v2 plus an incremental update'), $last)
            ->assertJsonPath('verdict', 'signature_valid_file_changed')
            ->assertJsonPath('document.version', 2);

        // The prefix is only a fallback: an exact whole-file match still wins.
        ($this->lookup)($first->signed_document_hash, $last)->assertJsonPath('verdict', 'superseded');
    });

    it('calls a copy from a cancelled session withdrawn', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        app(SigningSessionManager::class)->cancel($this->session);

        ($this->lookup)($first->signed_document_hash)->assertJsonPath('verdict', 'withdrawn');
    });

    it('shows a declined slot as declined', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        $request = $this->session->requests()->where('slot_key', 'reviewer')->first();
        app(SigningSessionManager::class)->decline($request, $this->boss->user_id, 'Wrong period');

        $response = ($this->lookup)($first->signed_document_hash);

        expect(($this->slot)($response, 'reviewer')['state'])->toBe('declined')
            ->and(json_encode($response->json()))->not->toContain('Wrong period');
    });

    it('reports a signature whose certificate was revoked since', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);
        $first->update(['certificate_fingerprint' => str_repeat('c', 64)]);

        UserCertificate::create([
            'user_id'     => $this->juana->user_id,
            'pfx_path'    => 'certs/juana.pfx',
            'fingerprint' => str_repeat('c', 64),
            'revoked_at'  => now(),
        ]);

        expect(($this->slot)(($this->lookup)($first->signed_document_hash), 'applicant')['certificate'])->toBe('revoked');
    });

    it('never discloses emails, paths or the record behind the document', function () {
        ($this->signAs)('applicant', $this->juana->user_id);
        $last = ($this->signAs)('reviewer', $this->boss->user_id);

        $body = json_encode(($this->lookup)($last->signed_document_hash)->json());

        expect($body)->not->toContain('@')
            ->and($body)->not->toContain('signed-docs/')
            ->and($body)->not->toContain('leave_form')
            ->and($body)->not->toContain((string) $this->form->getKey().'"');
    });

    it('accepts fingerprints in either case', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);

        ($this->lookup)(strtoupper($first->signed_document_hash))->assertJsonPath('verdict', 'in_progress');
    });

    it('refuses anything that is not a SHA-256', function () {
        $this->postJson('/signature/verify/lookup', ['sha256' => 'not-a-hash'])->assertStatus(422);
        $this->postJson('/signature/verify/lookup', [])->assertStatus(422);
        $this->postJson('/signature/verify/lookup', ['sha256' => str_repeat('a', 64), 'signed_revision_sha256' => 'x'])->assertStatus(422);
    });

    it('answers the lookup with no session or CSRF, so the desktop agent can call it', function () {
        $middleware = Route::getRoutes()->getByName('signature.verify.lookup')->gatherMiddleware();

        expect($middleware)->not->toContain('web');
    });

    it('is the same lookup the agent and the hub will use', function () {
        $first = ($this->signAs)('applicant', $this->juana->user_id);

        expect(app(DocumentLookup::class)->find($first->signed_document_hash)->verdict)->toBe('in_progress');
    });
});

describe('document verification page', function () {

    it('serves the drop-a-PDF page to someone who is not signed in', function () {
        $this->get('/signature/verify')
            ->assertOk()
            ->assertSee('Verify a signed document')
            ->assertSee('Only its fingerprint', false)
            ->assertSee(route('signature.verify.lookup'), false)
            ->assertSee('/signature/verify/assets/verify.js?v=', false);
    });

    it('serves its own script, so no asset publishing step can break it', function () {
        $response = $this->get('/signature/verify/assets/verify.js')->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('javascript')
            ->and($response->getContent())->not->toBe('');
    });

    it('hands out the CA certificate signatures are checked against', function () {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n";
        $path = tempnam(sys_get_temp_dir(), 'dsig_ca_');
        file_put_contents($path, $pem);
        config()->set('signature.openssl.ca_cert_path', $path);

        $this->get('/signature/trust/ca.pem')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-pem-file');

        expect($this->get('/signature/trust/ca.pem')->getContent())->toBe($pem);

        @unlink($path);
    });

    it('says plainly when there is no CA to hand out', function () {
        config()->set('signature.openssl.ca_cert_path', '/nonexistent/ca.crt');

        $this->get('/signature/trust/ca.pem')->assertNotFound();
    });

    it('can be switched off on its own, or with the rest of verification', function (string $key) {
        config()->set($key, false);

        $this->get('/signature/verify')->assertNotFound();
        $this->postJson('/signature/verify/lookup', ['sha256' => str_repeat('a', 64)])->assertNotFound();
        $this->get('/signature/trust/ca.pem')->assertNotFound();
        $this->get('/signature/verify/assets/verify.js')->assertNotFound();
    })->with(['signature.verify.document_lookup', 'signature.verify.enabled']);
});
