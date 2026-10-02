<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Exceptions\AgentApprovalRequiredException;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/*
 * The server side of digital-signature-agent/docs/protocol.md, driven the
 * way the real agent drives it: P-256 keys, ES256 DER signatures, and every
 * authenticated call carrying X-Agent-Proof.
 */

const AGENT_VERSION = '1.0.0';

function agentKey(): array
{
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $spki = preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($private)['key']);

    return ['private' => $private, 'spki' => $spki];
}

function agentSign(array $key, string $message): string
{
    openssl_sign($message, $der, $key['private'], OPENSSL_ALGO_SHA256);

    return base64_encode($der);
}

function b64url(int $bytes = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** An unauthenticated agent call (pairing). */
function agentPost($test, string $path, array $body, string $version = AGENT_VERSION): \Illuminate\Testing\TestResponse
{
    return $test->call('POST', $path, [], [], [], [
        'CONTENT_TYPE'        => 'application/json',
        'HTTP_ACCEPT'         => 'application/json',
        'HTTP_X_AGENT_VERSION' => $version,
    ], json_encode($body));
}

/** An authenticated agent call: bearer token + session-key request proof. */
function agentCall($test, array $agent, string $method, string $path, ?array $body = null, array $overrides = []): \Illuminate\Testing\TestResponse
{
    $raw = $body === null ? '' : json_encode($body);
    $timestamp = (string) ($overrides['timestamp'] ?? now()->getTimestamp());
    $nonce = $overrides['nonce'] ?? b64url();
    $payload = hash('sha256', "{$method}|{$path}|{$raw}|{$timestamp}");
    $proof = $overrides['proof'] ?? agentSign(
        $overrides['session'] ?? $agent['session'],
        DeviceProofVerifier::message('request', $nonce, $agent['user_id'], $payload),
    );

    return $test->call($method, $path, [], [], [], array_filter([
        'CONTENT_TYPE'          => $body === null ? null : 'application/json',
        'HTTP_ACCEPT'           => 'application/json',
        'HTTP_X_AGENT_VERSION'  => AGENT_VERSION,
        'HTTP_AUTHORIZATION'    => 'Bearer '.($overrides['token'] ?? $agent['token']),
        'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
        'HTTP_X_AGENT_NONCE'    => $nonce,
        'HTTP_X_AGENT_PROOF'    => $proof,
    ]), $raw);
}

function agentUser(int $id = 42): TestUser
{
    $user = TestUser::create(['id' => $id, 'name' => 'Juan dela Cruz', 'email' => "juan{$id}@example.test"]);
    Auth::login($user);

    return $user;
}

/**
 * The whole pairing dance: web start → agent lookup → claim → web confirm →
 * agent poll for its token. Returns what the agent keeps.
 */
function pairAgent($test, TestUser $user, array $deviceOverrides = []): array
{
    $identity = agentKey();
    $session = agentKey();

    $started = app(AgentPairingService::class)->start($user->id);

    $lookup = agentPost($test, '/signature/agent/pairings/lookup', ['user_code' => $started['user_code']])
        ->assertOk()
        ->json();

    $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

    $claim = agentPost($test, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
        'user_code'           => $started['user_code'],
        'algorithm'           => 'ES256',
        'identity_public_key' => $identity['spki'],
        'session_public_key'  => $session['spki'],
        'protection'          => 'secure_enclave',
        'user_presence'       => true,
        'attestation'         => null,
        'device'              => array_merge([
            'platform' => 'macos', 'os_version' => '15.1.0', 'model' => 'MacBook Pro',
            'model_identifier' => 'Mac15,3', 'form_factor' => 'laptop',
            'label' => 'Juan’s MacBook Pro', 'hardware_id_hash' => str_repeat('ab', 32),
        ], $deviceOverrides),
        'agent_version'       => AGENT_VERSION,
        'proof'               => agentSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], $lookup['user_id'], $bound)),
    ])->assertOk()->json();

    $device = app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $lookup['pairing'])->firstOrFail(), $user->id);

    $poll = agentPost($test, "/signature/agent/pairings/{$lookup['pairing']}/poll", ['poll_secret' => $claim['poll_secret']])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->json();

    return [
        'identity'    => $identity,
        'session'     => $session,
        'token'       => $poll['token'],
        'user_id'     => $lookup['user_id'],
        'device'      => $device,
        'pairing'     => $lookup['pairing'],
        'poll_secret' => $claim['poll_secret'],
        'lookup'      => $lookup,
    ];
}

function agentDocument(): Signable
{
    Storage::disk('testing')->put('documents/report.pdf', minimalPdf());

    return new class extends Model implements Signable
    {
        protected $table = 'reports';

        public function getSignableTitle(): string
        {
            return 'Accomplishment Report – Sept';
        }

        public function getSignablePdfPath(): string
        {
            return 'documents/report.pdf';
        }

        public function getSignableId(): int|string
        {
            return 9;
        }
    };
}

function parseJobLink(string $link): array
{
    expect($link)->toMatch('#^kukuxsign://job/[0-9a-f-]{36}\?t=[A-Za-z0-9_-]{43}&s=[A-Za-z0-9_-]{1,64}$#');

    $parts = parse_url($link);
    parse_str($parts['query'], $query);

    return ['uuid' => ltrim($parts['path'], '/'), 'token' => $query['t'], 'server' => $query['s']];
}

beforeEach(function () {
    config(['signature.devices.agent.enabled' => true]);
    Storage::fake('testing');
    Notification::fake();
});

describe('shared canonical vectors (from the agent repo)', function () {

    it('builds byte-identical proof messages', function () {
        $vectors = json_decode(file_get_contents(__DIR__.'/../Fixtures/agent-canonical-vectors.json'), true);

        foreach ($vectors['messages'] as $v) {
            expect(DeviceProofVerifier::message($v['purpose'], $v['nonce'], $v['user_id'], $v['payload_hash']))->toBe($v['message']);
        }

        foreach ($vectors['requests'] as $v) {
            $input = implode('|', [$v['method'], $v['path'], $v['body'], $v['timestamp']]);
            expect($input)->toBe($v['input'])->and(hash('sha256', $input))->toBe($v['payload_hash']);
        }

        foreach ($vectors['registrations'] as $v) {
            expect(hash('sha256', base64_decode($v['identity_spki']).base64_decode($v['session_spki'])))->toBe($v['payload_hash']);
        }
    });
});

describe('pairing', function () {

    it('pairs an agent end to end and registers it as a hardware device', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);

        $device = $agent['device']->fresh();

        expect($device->kind)->toBe('agent')
            ->and($device->protection)->toBe('secure_enclave')
            ->and($device->user_presence)->toBeTrue()
            ->and($device->label)->toBe('Juan’s MacBook Pro')
            ->and($device->platform)->toBe('macOS')
            ->and($device->form_factor)->toBe('laptop')
            ->and($device->model)->toBe('MacBook Pro')
            ->and($device->agent_version)->toBe(AGENT_VERSION)
            ->and($device->displayName())->toBe('Juan’s MacBook Pro')
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_PAIRED)->exists())->toBeTrue();
    });

    it('describes this server the way the agent checks it', function () {
        $user = agentUser();
        $started = app(AgentPairingService::class)->start($user->id);

        expect($started['user_code'])->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/')
            ->and($started['link'])->toBe('kukuxsign://pair?o='.rawurlencode('http://localhost').'&c='.$started['user_code']);

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => strtolower(str_replace('-', ' ', $started['user_code']))])
            ->assertOk()
            ->assertJsonPath('user_id', '42')
            ->assertJsonPath('user_name', 'Juan dela Cruz')
            ->assertJsonPath('server.id', AgentServer::id())
            ->assertJsonPath('server.origin', 'http://localhost')
            ->assertJsonPath('server.salt', AgentServer::salt())
            ->assertJsonPath('require_presence', true);

        expect(AgentServer::id())->toMatch('/^[A-Za-z0-9_-]{1,64}$/');
    });

    it('answers unknown codes in the protocol error shape', function () {
        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => 'AAAA-BBBB'])
            ->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'invalid_code', 'message' => 'That code is invalid or has expired.']]);
    });

    it('refuses a claim whose proof does not cover both keys', function () {
        $user = agentUser();
        $started = app(AgentPairingService::class)->start($user->id);
        $lookup = agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $started['user_code']])->json();
        [$identity, $session, $swapped] = [agentKey(), agentKey(), agentKey()];

        // Signed over a different session key than the one sent.
        $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($swapped['spki']));

        agentPost($this, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code' => $started['user_code'], 'algorithm' => 'ES256',
            'identity_public_key' => $identity['spki'], 'session_public_key' => $session['spki'],
            'protection' => 'secure_enclave', 'user_presence' => true, 'device' => [], 'agent_version' => AGENT_VERSION,
            'proof' => agentSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], '42', $bound)),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_proof');
    });

    it('requires user presence when configured', function () {
        $user = agentUser();
        $started = app(AgentPairingService::class)->start($user->id);
        $lookup = agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $started['user_code']])->json();
        [$identity, $session] = [agentKey(), agentKey()];
        $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

        agentPost($this, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code' => $started['user_code'], 'algorithm' => 'ES256',
            'identity_public_key' => $identity['spki'], 'session_public_key' => $session['spki'],
            'protection' => 'software', 'user_presence' => false, 'device' => [], 'agent_version' => AGENT_VERSION,
            'proof' => agentSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], '42', $bound)),
        ])->assertStatus(422)->assertJsonPath('error.code', 'presence_required');
    });

    it('issues the token exactly once', function () {
        $agent = pairAgent($this, agentUser());

        agentPost($this, "/signature/agent/pairings/{$agent['pairing']}/poll", ['poll_secret' => $agent['poll_secret']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'token_already_issued');
    });

    it('reports a rejected pairing to the polling agent', function () {
        $user = agentUser();
        $started = app(AgentPairingService::class)->start($user->id);
        $lookup = agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $started['user_code']])->json();
        [$identity, $session] = [agentKey(), agentKey()];
        $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

        $claim = agentPost($this, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code' => $started['user_code'], 'algorithm' => 'ES256',
            'identity_public_key' => $identity['spki'], 'session_public_key' => $session['spki'],
            'protection' => 'tpm', 'user_presence' => true, 'device' => ['platform' => 'windows'], 'agent_version' => AGENT_VERSION,
            'proof' => agentSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], '42', $bound)),
        ])->json();

        app(AgentPairingService::class)->reject($started['pairing']->fresh(), $user->id);

        agentPost($this, "/signature/agent/pairings/{$lookup['pairing']}/poll", ['poll_secret' => $claim['poll_secret']])
            ->assertOk()
            ->assertJson(['status' => 'rejected']);

        expect(SigningDevice::count())->toBe(0);
    });

    it('replaces the old device when the same machine pairs again', function () {
        $user = agentUser();
        $first = pairAgent($this, $user);
        $second = pairAgent($this, $user);

        expect($first['device']->fresh()->status)->toBe('revoked')
            ->and($second['device']->fresh()->status)->toBe('active');

        // The old pairing's token died with its device.
        agentCall($this, $first, 'GET', '/signature/agent/status')->assertUnauthorized();
    });

    it('refuses outdated agents with 426', function () {
        config(['signature.devices.agent.min_version' => '2.0.0']);

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => 'AAAA-BBBB'], '1.9.9')
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'agent_outdated')
            ->assertJsonPath('min_version', '2.0.0');
    });

    it('is closed when the agent feature is off', function () {
        config(['signature.devices.agent.enabled' => false]);

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => 'AAAA-BBBB'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'agent_disabled');
    });
});

describe('authenticated agent requests', function () {

    it('accepts a correctly signed request', function () {
        $agent = pairAgent($this, agentUser());

        agentCall($this, $agent, 'GET', '/signature/agent/status')
            ->assertOk()
            ->assertJsonPath('device.uuid', $agent['device']->uuid)
            ->assertJsonPath('device.status', 'active')
            ->assertJsonPath('user.id', '42');
    });

    it('refuses a replayed nonce', function () {
        $agent = pairAgent($this, agentUser());
        $nonce = b64url();

        agentCall($this, $agent, 'GET', '/signature/agent/status', null, ['nonce' => $nonce])->assertOk();
        agentCall($this, $agent, 'GET', '/signature/agent/status', null, ['nonce' => $nonce])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'replayed_request');
    });

    it('refuses a stale timestamp', function () {
        $agent = pairAgent($this, agentUser());

        agentCall($this, $agent, 'GET', '/signature/agent/status', null, ['timestamp' => now()->getTimestamp() - 61])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'stale_request');
    });

    it('refuses a token without its session key', function () {
        $agent = pairAgent($this, agentUser());

        agentCall($this, $agent, 'GET', '/signature/agent/status', null, ['session' => agentKey()])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_request_proof');
    });

    it('refuses an unknown token', function () {
        $agent = pairAgent($this, agentUser());

        agentCall($this, $agent, 'GET', '/signature/agent/status', null, ['token' => b64url(32)])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });

    it('binds the proof to the body: a tampered body fails', function () {
        $agent = pairAgent($this, agentUser());
        $timestamp = (string) now()->getTimestamp();
        $nonce = b64url();
        $path = '/signature/agent/jobs/'.\Illuminate\Support\Str::uuid().'/reject';
        $signedBody = json_encode(['reason' => 'declined']);
        $payload = hash('sha256', "POST|{$path}|{$signedBody}|{$timestamp}");
        $proof = agentSign($agent['session'], DeviceProofVerifier::message('request', $nonce, '42', $payload));

        agentCall($this, $agent, 'POST', $path, ['reason' => 'invalid_job'], compact('timestamp', 'nonce', 'proof'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_request_proof');
    });

    it('unpairs: DELETE /device revokes the device and its token', function () {
        $agent = pairAgent($this, agentUser());

        agentCall($this, $agent, 'DELETE', '/signature/agent/device')->assertNoContent();

        expect($agent['device']->fresh()->status)->toBe('revoked');
        agentCall($this, $agent, 'GET', '/signature/agent/status')->assertUnauthorized();
    });

    it('cuts off an agent revoked from the web', function () {
        $agent = pairAgent($this, agentUser());

        app(DeviceRegistry::class)->revoke($agent['device']->fresh());

        agentCall($this, $agent, 'GET', '/signature/agent/status')->assertUnauthorized();
    });
});

describe('signing jobs', function () {

    /** Sign a document as the user; return the approval the server asked for. */
    function requestApproval($primary, TestUser $user): AgentApprovalRequiredException
    {
        try {
            app()->forgetScopedInstances();
            app(SignatureManager::class)->storeForDocument($primary, $user->id, agentDocument());
        } catch (AgentApprovalRequiredException $e) {
            return $e;
        }

        throw new RuntimeException('Expected the server to ask for agent approval.');
    }

    function approveOnAgent($test, array $agent, string $link): array
    {
        $parsed = parseJobLink($link);
        expect($parsed['server'])->toBe(AgentServer::id());

        $job = agentCall($test, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/claim", ['link_token' => $parsed['token']])
            ->assertOk()
            ->assertJsonPath('purpose', 'sign_receipt')
            ->assertJsonPath('user_id', '42')
            ->assertJsonPath('document.title', 'Accomplishment Report – Sept')
            ->assertJsonPath('signer.name', 'Juan dela Cruz')
            ->json();

        agentCall($test, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/complete", [
            'proof' => agentSign($agent['identity'], DeviceProofVerifier::message('sign_receipt', $job['nonce'], $job['user_id'], $job['payload_hash'])),
        ])->assertOk()->assertJson(['status' => 'completed']);

        return $job + ['token' => $parsed['token']];
    }

    it('records the paired computer as the device a document was signed on', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $primary = makePrimarySignature($user->id);

        $approval = requestApproval($primary, $user);

        expect($approval->agentApproval())->toMatchArray([
            'device' => 'Juan’s MacBook Pro',
            'title'  => 'Accomplishment Report – Sept',
        ])->and($approval->job->payload_hash)->toBe(hash('sha256', minimalPdf()));

        $job = approveOnAgent($this, $agent, $approval->link);

        $this->getJson(route('signature.agent.web.job', $approval->job->uuid))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('device.label', 'Juan’s MacBook Pro');

        // The browser retries the same signing.
        app()->forgetScopedInstances();
        $signed = app(SignatureManager::class)->storeForDocument($primary, $user->id, agentDocument());

        expect($signed->device_id)->toBe($agent['device']->id)
            ->and($signed->deviceSummary())->toBe('Juan’s MacBook Pro · Secure Enclave')
            ->and(AgentJob::where('uuid', $job['uuid'])->first()->signature_id)->toBe($signed->id)
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_APPROVED)->first()->device_id)->toBe($agent['device']->id);
    });

    it('spends an approval once', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $primary = makePrimarySignature($user->id);

        approveOnAgent($this, $agent, requestApproval($primary, $user)->link);

        app()->forgetScopedInstances();
        app(SignatureManager::class)->storeForDocument($primary, $user->id, agentDocument());

        // A second signing of the same document needs a fresh approval.
        expect(requestApproval($primary, $user))->toBeInstanceOf(AgentApprovalRequiredException::class);
    });

    it('makes the link token single use', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $parsed = parseJobLink(requestApproval(makePrimarySignature($user->id), $user)->link);

        agentCall($this, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/claim", ['link_token' => $parsed['token']])->assertOk();
        agentCall($this, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/claim", ['link_token' => $parsed['token']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'job_unavailable');
    });

    it('refuses a receipt signed by any key but the identity key', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $parsed = parseJobLink(requestApproval(makePrimarySignature($user->id), $user)->link);

        $job = agentCall($this, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/claim", ['link_token' => $parsed['token']])->json();

        agentCall($this, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/complete", [
            // The session key is in the same Secure Enclave, but it is not the
            // key that needs Touch ID.
            'proof' => agentSign($agent['session'], DeviceProofVerifier::message('sign_receipt', $job['nonce'], '42', $job['payload_hash'])),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_proof');
    });

    it('keeps one user\'s agent away from another user\'s jobs', function () {
        $owner = agentUser(42);
        pairAgent($this, $owner);
        $parsed = parseJobLink(requestApproval(makePrimarySignature($owner->id), $owner)->link);

        $other = agentUser(43);
        $intruder = pairAgent($this, $other, ['hardware_id_hash' => str_repeat('cd', 32)]);

        agentCall($this, $intruder, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/claim", ['link_token' => $parsed['token']])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'job_not_found');
    });

    it('reports a decline on the computer to the waiting page', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $approval = requestApproval(makePrimarySignature($user->id), $user);
        $parsed = parseJobLink($approval->link);

        agentCall($this, $agent, 'POST', "/signature/agent/jobs/{$parsed['uuid']}/reject", ['reason' => 'os_prompt_cancelled'])
            ->assertOk()
            ->assertJson(['status' => 'rejected']);

        $this->getJson(route('signature.agent.web.job', $approval->job->uuid))
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'os_prompt_cancelled');
    });

    it('expires an unanswered job', function () {
        $user = agentUser();
        pairAgent($this, $user);
        $approval = requestApproval(makePrimarySignature($user->id), $user);

        $this->travel(config('signature.devices.agent.job_ttl') + 1)->seconds();

        $this->getJson(route('signature.agent.web.job', $approval->job->uuid))->assertJsonPath('status', 'expired');
    });

    it('lets the signer sign in the browser instead under prefer', function () {
        $user = agentUser();
        pairAgent($this, $user);
        $primary = makePrimarySignature($user->id);

        $this->postJson(route('signature.agent.web.skip'))->assertOk();

        app()->forgetScopedInstances();
        $signed = app(SignatureManager::class)->storeForDocument($primary, $user->id, agentDocument());

        expect($signed->device_id)->not->toBe(SigningDevice::where('kind', 'agent')->value('id'));
    });

    it('does not allow skipping under enforce', function () {
        config(['signature.devices.agent.approval' => 'enforce']);
        agentUser();

        $this->postJson(route('signature.agent.web.skip'))->assertForbidden();
    });

    it('requires a paired computer under enforce', function () {
        config(['signature.devices.agent.approval' => 'enforce']);
        $user = agentUser();

        app()->forgetScopedInstances();

        expect(fn () => app(SignatureManager::class)->storeForDocument(makePrimarySignature($user->id), $user->id, agentDocument()))
            ->toThrow(UnregisteredDeviceException::class);
    });

    it('signs as before for users without a paired computer', function () {
        $user = agentUser();

        app()->forgetScopedInstances();
        $signed = app(SignatureManager::class)->storeForDocument(makePrimarySignature($user->id), $user->id, agentDocument());

        expect($signed->exists)->toBeTrue();
    });

    it('never asks the agent for delegated signing', function () {
        $user = agentUser();
        pairAgent($this, $user);

        $signed = app(SignatureManager::class)->storeDelegated(makePrimarySignature($user->id), agentDocument());

        expect($signed->device_id)->toBeNull()->and(AgentJob::count())->toBe(0);
    });

    it('shows the waiting page only its own jobs', function () {
        $owner = agentUser(42);
        pairAgent($this, $owner);
        $approval = requestApproval(makePrimarySignature($owner->id), $owner);

        agentUser(43);

        $this->getJson(route('signature.agent.web.job', $approval->job->uuid))->assertNotFound();
    });
});

describe('signing devices component', function () {

    it('starts a pairing, then confirms the claimed computer', function () {
        $user = agentUser();

        $component = \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)
            ->call('startPairing')
            ->assertSee('Enter this code in Kukux Sign Agent');

        $code = $component->get('userCode');
        $lookup = agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $code])->json();
        [$identity, $session] = [agentKey(), agentKey()];
        $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

        agentPost($this, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code' => $code, 'algorithm' => 'ES256',
            'identity_public_key' => $identity['spki'], 'session_public_key' => $session['spki'],
            'protection' => 'secure_enclave', 'user_presence' => true,
            'device' => ['platform' => 'macos', 'os_version' => '15.1.0', 'model' => 'MacBook Pro', 'label' => 'Work Mac', 'form_factor' => 'laptop'],
            'agent_version' => AGENT_VERSION,
            'proof' => agentSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], '42', $bound)),
        ])->assertOk();

        $component->call('refreshPairing')
            ->assertSee('Pair Work Mac?')
            ->call('confirmPairing')
            ->assertSee('Paired Work Mac')
            ->assertSee('Secure Enclave');

        expect(SigningDevice::where('kind', 'agent')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('renames and revokes only the user\'s own devices', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);

        \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)
            ->call('startRename', $agent['device']->uuid)
            ->set('renameLabel', 'Office iMac')
            ->call('saveRename')
            ->assertSee('Office iMac')
            ->call('revoke', $agent['device']->uuid)
            ->assertSee('can no longer sign as you');

        expect($agent['device']->fresh()->status)->toBe('revoked');
    });
});
