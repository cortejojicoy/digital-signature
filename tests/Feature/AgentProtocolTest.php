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
 * The agent's half of pairing, up to its claim: lookup, then claim with fresh
 * keys and a registration proof. Starts a pairing on the web unless given the
 * code of one. The claim response is returned unchecked.
 */
function claimAgent($test, TestUser $user, array $deviceOverrides = [], ?string $userCode = null, ?array $previous = null): array
{
    $identity = agentKey();
    $session = agentKey();

    $userCode ??= app(AgentPairingService::class)->start($user->id)['user_code'];

    $lookup = agentPost($test, '/signature/agent/pairings/lookup', ['user_code' => $userCode])
        ->assertOk()
        ->json();

    $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

    $response = agentPost($test, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
        'user_code'           => $userCode,
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
        // Re-pairing from the install that holds $previous: its session key vouches for the new keys.
        'replaces'            => $previous === null ? null : [
            'device_uuid' => $previous['device']->uuid,
            'proof'       => agentSign($previous['session'], DeviceProofVerifier::message('rebind_agent', $lookup['nonce'], $lookup['user_id'], $bound)),
        ],
    ]);

    return ['identity' => $identity, 'session' => $session, 'lookup' => $lookup, 'response' => $response];
}

/**
 * The whole pairing dance: web start → agent lookup → claim → web confirm →
 * agent poll for its token. Returns what the agent keeps.
 */
function pairAgent($test, TestUser $user, array $deviceOverrides = [], ?string $deviceType = null, ?array $previous = null): array
{
    ['identity' => $identity, 'session' => $session, 'lookup' => $lookup, 'response' => $response] = claimAgent($test, $user, $deviceOverrides, null, $previous);
    $claim = $response->assertOk()->json();

    $device = app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $lookup['pairing'])->firstOrFail(), $user->id, $deviceType);

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
        'claim'       => $claim,
        'poll'        => $poll,
    ];
}

/**
 * A device row made directly, without pairing: a browser profile, or an agent
 * computer that appears between a claim and its confirm.
 */
function signingDevice(int $userId, string $kind = 'browser', array $attributes = []): SigningDevice
{
    return SigningDevice::create($attributes + [
        'uuid'            => (string) \Illuminate\Support\Str::uuid(),
        'user_id'         => $userId,
        'label'           => $kind === 'agent' ? 'Office Mac mini' : 'Chrome on Mac',
        'public_key'      => 'test',
        'key_fingerprint' => bin2hex(random_bytes(32)),
        'algorithm'       => 'ES256',
        'kind'            => $kind,
        'protection'      => $kind === 'agent' ? 'secure_enclave' : 'browser',
        'device_type'     => $kind === 'agent' ? 'mac_mini' : 'desktop',
        'model'           => $kind === 'agent' ? 'Mac mini (2024)' : null,
        'hardware_id_hash' => $kind === 'agent' ? str_repeat('cd', 32) : null,
        'status'          => 'active',
    ]);
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

describe('presence checks', function () {

    beforeEach(function () {
        config(['signature.devices.agent.approval' => 'enforce']);
    });

    function startPresence($test): array
    {
        $check = $test->postJson('/signature/agent-web/presence')->assertOk()->json();

        expect($check['link'])->toMatch('#^kukuxsign://presence/[0-9a-f-]{36}\?t=[A-Za-z0-9_-]{43}&s=[A-Za-z0-9_-]{1,64}$#');

        $parts = parse_url($check['link']);
        parse_str($parts['query'], $query);

        return ['uuid' => $check['uuid'], 'token' => $query['t'], 'server' => $query['s']];
    }

    it('confirms the paired computer and remembers it for the session', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);

        $check = startPresence($this);
        expect($check['server'])->toBe(AgentServer::id());

        $this->getJson("/signature/agent-web/presence/{$check['uuid']}")->assertOk()->assertJson(['status' => 'pending']);

        agentCall($this, $agent, 'POST', "/signature/agent/presence/{$check['uuid']}", ['link_token' => $check['token']])
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        $this->getJson("/signature/agent-web/presence/{$check['uuid']}")->assertOk()->assertJson(['status' => 'confirmed']);

        expect(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->here($user->id)?->id)->toBe($agent['device']->id)
            ->and(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->refusal($user->id))->toBeNull();
    });

    it("tells the page when the computer answering is another account's", function () {
        $other = agentUser(43);
        $otherAgent = pairAgent($this, $other, ['hardware_id_hash' => str_repeat('ef', 32)]);

        $user = agentUser();
        pairAgent($this, $user);
        $check = startPresence($this);

        agentCall($this, $otherAgent, 'POST', "/signature/agent/presence/{$check['uuid']}", ['link_token' => $check['token']])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'wrong_account');

        $this->getJson("/signature/agent-web/presence/{$check['uuid']}")->assertOk()->assertJson(['status' => 'other_account']);

        expect(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->refusal($user->id))
            ->toContain('prohibited from signing on this computer');
    });

    it('makes the link token single use', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $check = startPresence($this);

        agentCall($this, $agent, 'POST', "/signature/agent/presence/{$check['uuid']}", ['link_token' => $check['token']])->assertOk();
        agentCall($this, $agent, 'POST', "/signature/agent/presence/{$check['uuid']}", ['link_token' => $check['token']])
            ->assertStatus(409);
    });

    it('refuses a wrong link token', function () {
        $user = agentUser();
        $agent = pairAgent($this, $user);
        $check = startPresence($this);

        agentCall($this, $agent, 'POST', "/signature/agent/presence/{$check['uuid']}", ['link_token' => b64url(32)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'invalid_link_token');
    });

    it('expires an unanswered check', function () {
        $user = agentUser();
        pairAgent($this, $user);
        $check = startPresence($this);

        $this->travel(2)->minutes();

        $this->getJson("/signature/agent-web/presence/{$check['uuid']}")->assertOk()->assertJson(['status' => 'expired']);
        expect(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->refusal($user->id))
            ->toContain('Your account is paired with');
    });

    it('says when there is no paired computer to check for', function () {
        $user = agentUser();

        $this->postJson('/signature/agent-web/presence')->assertStatus(409)->assertJson(['status' => 'unpaired']);
        expect(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->refusal($user->id))
            ->toContain('pair Kukux Sign Agent');
    });

    it("shows a check only to the user who started it", function () {
        $user = agentUser();
        pairAgent($this, $user);
        $check = startPresence($this);

        agentUser(43);
        $this->getJson("/signature/agent-web/presence/{$check['uuid']}")->assertNotFound();
    });

    it('is not required outside enforce', function () {
        config(['signature.devices.agent.approval' => 'prefer']);
        $user = agentUser();

        $this->postJson('/signature/agent-web/presence')->assertNotFound();
        expect(app(\Kukux\DigitalSignature\Agent\AgentPresenceService::class)->refusal($user->id))->toBeNull();
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

// ── One signature per computer for this app (multi-app-pairing-plan.md §7) ──

describe('one signature per computer', function () {

    it('re-pairing from the same computer updates its device instead of adding one', function () {
        $user = agentUser();
        $first = pairAgent($this, $user);
        $before = $first['device']->fresh();
        $second = pairAgent($this, $user);
        $after = $second['device']->fresh();

        expect($after->id)->toBe($before->id)
            ->and($after->uuid)->toBe($before->uuid)
            ->and($after->label)->toBe($before->label)
            ->and($after->status)->toBe('active')
            ->and($after->key_fingerprint)->not->toBe($before->key_fingerprint)
            ->and($after->rebound_at)->not->toBeNull()
            ->and(SigningDevice::where('kind', 'agent')->count())->toBe(1)
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_REBOUND)->where('device_id', $after->id)->exists())->toBeTrue();

        // The agent heard about the existing device at claim, and the rebind at poll.
        expect($first['claim']['existing_device'])->toBeNull()
            ->and($first['poll']['rebound'])->toBeFalse()
            ->and($second['claim']['existing_device'])->toMatchArray(['uuid' => $before->uuid, 'label' => 'Juan’s MacBook Pro', 'device_type' => 'laptop'])
            ->and($second['poll']['rebound'])->toBeTrue()
            ->and($second['poll']['device']['uuid'])->toBe($before->uuid);

        // The old keys and token stopped working; the new ones work.
        agentCall($this, $first, 'GET', '/signature/agent/status')->assertUnauthorized();
        agentCall($this, $second, 'GET', '/signature/agent/status')->assertOk();
    });

    it('refuses another account on a computer that is already paired, without naming the owner', function () {
        $juan = agentUser(42);
        $juansComputer = pairAgent($this, $juan)['device'];
        $maria = agentUser(7);

        $claim = claimAgent($this, $maria);

        $claim['response']->assertStatus(409)->assertJsonPath('error.code', 'machine_already_paired');
        expect($claim['response']->json('error.message'))->not->toContain('Juan')
            ->and($juansComputer->fresh()->status)->toBe('active')
            ->and(AgentPairing::where('uuid', $claim['lookup']['pairing'])->value('status'))->toBe('pending')
            ->and(SigningDevice::where('user_id', 7)->count())->toBe(0);
    });

    it('lets another account pair once the owner removes the computer', function () {
        $juan = agentUser(42);
        $device = pairAgent($this, $juan)['device'];
        app(DeviceRegistry::class)->revoke($device);

        expect($device->fresh()->active_hardware_key)->toBeNull();

        $maria = agentUser(7);
        $paired = pairAgent($this, $maria);

        expect($paired['device']->user_id)->toBe(7)
            ->and($paired['poll']['rebound'])->toBeFalse();
    });

    it('checks again on confirm, when two accounts claim one computer at once', function () {
        $juan = agentUser(42);
        $juansClaim = claimAgent($this, $juan);
        $maria = agentUser(7);
        $mariasClaim = claimAgent($this, $maria);

        // Neither held the computer when they claimed.
        $juansClaim['response']->assertOk();
        $mariasClaim['response']->assertOk();

        $pairings = app(AgentPairingService::class);
        $pairings->confirm(AgentPairing::where('uuid', $juansClaim['lookup']['pairing'])->firstOrFail(), 42);

        expect(fn () => $pairings->confirm(AgentPairing::where('uuid', $mariasClaim['lookup']['pairing'])->firstOrFail(), 7))
            ->toThrow(InvalidArgumentException::class, 'already paired with another account');
        expect(SigningDevice::where('user_id', 7)->count())->toBe(0);
    });

    it('enforces one active device per computer in the database itself', function () {
        $juan = agentUser(42);
        $device = pairAgent($this, $juan)['device'];
        agentUser(7);

        $clone = $device->replicate()->fill([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => 7,
            'key_fingerprint' => str_repeat('cd', 32),
        ]);

        expect(fn () => $clone->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    });

    it('cannot tell computers apart without a hardware id, so it lets both pair', function () {
        $juan = agentUser(42);
        pairAgent($this, $juan, ['hardware_id_hash' => null]);
        $maria = agentUser(7);

        $claim = claimAgent($this, $maria, ['hardware_id_hash' => null]);
        $claim['response']->assertOk();

        $describe = app(AgentPairingService::class)->describeClaim(AgentPairing::where('uuid', $claim['lookup']['pairing'])->firstOrFail());
        expect($describe['identified'])->toBeFalse()
            ->and($describe['replaces'])->toBeNull();
    });

    it("lists the account's other devices to the agent", function () {
        $juan = agentUser(42);
        $laptop = pairAgent($this, $juan);
        signingDevice(42);

        agentCall($this, $laptop, 'GET', '/signature/agent/status')
            ->assertOk()
            ->assertJsonCount(1, 'other_devices')
            ->assertJsonPath('other_devices.0.label', 'Chrome on Mac')
            ->assertJsonPath('other_devices.0.device_type', 'desktop');
    });
});

// ── One computer per account for this app (one-computer-per-account-plan.md) ──

describe('one computer per account', function () {

    it('refuses a second computer for the same account, naming the first', function () {
        $juan = agentUser(42);
        $first = pairAgent($this, $juan)['device'];

        $claim = claimAgent($this, $juan, ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Office Mac mini']);

        $claim['response']->assertStatus(409)
            ->assertJsonPath('error.code', 'account_already_paired')
            ->assertJsonPath('device.label', 'Juan’s MacBook Pro')
            ->assertJsonPath('device.device_type', 'laptop');
        expect($claim['response']->json('error.message'))->toContain('Juan’s MacBook Pro')
            ->and($first->fresh()->status)->toBe('active')
            ->and(AgentPairing::where('uuid', $claim['lookup']['pairing'])->value('status'))->toBe('pending')
            ->and(SigningDevice::where('kind', 'agent')->count())->toBe(1);
    });

    it("gives the agent the account's computer at lookup", function () {
        $juan = agentUser(42);
        $code = fn () => app(AgentPairingService::class)->start($juan->id)['user_code'];

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $code()])
            ->assertJsonPath('agent_device', null)
            ->assertJsonPath('devices_url', AgentServer::origin());

        $device = pairAgent($this, $juan)['device'];
        config(['signature.devices.agent.devices_url' => AgentServer::origin().'/admin/signatures']);

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $code()])
            ->assertJsonPath('agent_device.uuid', $device->uuid)
            ->assertJsonPath('agent_device.label', 'Juan’s MacBook Pro')
            ->assertJsonPath('agent_device.hardware_id_hash', str_repeat('ab', 32))
            ->assertJsonPath('devices_url', AgentServer::origin().'/admin/signatures');
    });

    it('frees the account once its computer is revoked', function () {
        $juan = agentUser(42);
        $first = pairAgent($this, $juan)['device'];
        app(DeviceRegistry::class)->revoke($first);

        expect($first->fresh()->active_agent_user_key)->toBeNull();

        $second = pairAgent($this, $juan, ['hardware_id_hash' => str_repeat('cd', 32)]);
        expect($second['device']->id)->not->toBe($first->id)
            ->and($second['poll']['rebound'])->toBeFalse();
    });

    it('checks the account again on confirm', function () {
        $juan = agentUser(42);
        $claim = claimAgent($this, $juan);
        $claim['response']->assertOk();

        // Another computer was paired between the claim and the confirm.
        signingDevice(42, 'agent');

        expect(fn () => app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $claim['lookup']['pairing'])->firstOrFail(), 42))
            ->toThrow(InvalidArgumentException::class, 'already paired with another computer on this app: Office Mac mini');
        expect(SigningDevice::where('kind', 'agent')->count())->toBe(1);
    });

    it('enforces one active computer per account in the database itself', function () {
        $device = pairAgent($this, agentUser(42))['device'];

        $clone = $device->replicate()->fill([
            'uuid'             => (string) \Illuminate\Support\Str::uuid(),
            'key_fingerprint'  => str_repeat('cd', 32),
            'hardware_id_hash' => str_repeat('cd', 32),
        ]);

        expect(fn () => $clone->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    });

    it('cannot prove it is the same computer without a hardware id, so it refuses', function () {
        $juan = agentUser(42);
        pairAgent($this, $juan, ['hardware_id_hash' => null]);

        claimAgent($this, $juan, ['hardware_id_hash' => null])['response']
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_already_paired');
        claimAgent($this, $juan)['response']
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_already_paired');
    });

    it('reports a computer held by another account before its own computer', function () {
        $maria = agentUser(7);
        pairAgent($this, $maria, ['hardware_id_hash' => str_repeat('cd', 32)]);
        $juan = agentUser(42);
        pairAgent($this, $juan);

        Auth::login($maria);
        claimAgent($this, $maria)['response']
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'machine_already_paired');
    });

    it('keeps computers paired before the rule, but pairs no more until one is left', function () {
        $juan = agentUser(42);
        $laptop = pairAgent($this, $juan)['device'];
        // Paired under the old rule. As after the upgrade backfill, the
        // newest holds the account key and the older one is unkeyed.
        \Illuminate\Support\Facades\DB::table('digital_signature_devices')->where('id', $laptop->id)->update(['active_agent_user_key' => null]);
        $mini = signingDevice(42, 'agent');

        // Not even a re-pair of the laptop while the Mac mini is still paired.
        claimAgent($this, $juan)['response']
            ->assertStatus(409)
            ->assertJsonPath('device.label', 'Office Mac mini');
        expect($laptop->fresh()->status)->toBe('active')
            ->and($mini->fresh()->status)->toBe('active');

        app(DeviceRegistry::class)->revoke($mini);
        expect(pairAgent($this, $juan)['poll']['rebound'])->toBeTrue();
    });

    it('re-pairs a computer without a hardware id when its old key vouches for it', function () {
        $juan = agentUser(42);
        $first = pairAgent($this, $juan, ['hardware_id_hash' => null]);

        $second = pairAgent($this, $juan, ['hardware_id_hash' => null], null, $first);

        expect($second['claim']['existing_device']['uuid'])->toBe($first['device']->uuid)
            ->and($second['poll']['rebound'])->toBeTrue()
            ->and($second['device']->id)->toBe($first['device']->id)
            ->and(SigningDevice::where('kind', 'agent')->active()->count())->toBe(1);
        agentCall($this, $first, 'GET', '/signature/agent/status')->assertUnauthorized();
        agentCall($this, $second, 'GET', '/signature/agent/status')->assertOk();
    });

    it('follows a computer whose hardware id changed, on the old key\'s word', function () {
        $juan = agentUser(42);
        $first = pairAgent($this, $juan);

        $second = pairAgent($this, $juan, ['hardware_id_hash' => str_repeat('cd', 32)], null, $first);

        expect($second['device']->id)->toBe($first['device']->id)
            ->and($second['device']->fresh()->hardware_id_hash)->toBe(str_repeat('cd', 32));
    });

    it('refuses a re-pairing proof that does not verify', function () {
        $juan = agentUser(42);
        $first = pairAgent($this, $juan, ['hardware_id_hash' => null]);
        $forged = ['device' => $first['device'], 'session' => agentKey()];

        claimAgent($this, $juan, ['hardware_id_hash' => null], null, $forged)['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_proof');
    });

    it("ignores a proof that names another account's device", function () {
        $maria = agentUser(7);
        $marias = pairAgent($this, $maria, ['hardware_id_hash' => str_repeat('cd', 32)]);
        $juan = agentUser(42);
        $juans = pairAgent($this, $juan);

        // Juan's install can't sign for Maria's device, and her device isn't his to name.
        claimAgent($this, $juan, ['hardware_id_hash' => str_repeat('ef', 32)], null, ['device' => $marias['device'], 'session' => $juans['session']])['response']
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_already_paired');
        expect($marias['device']->fresh()->status)->toBe('active');
    });

    it('treats a firmware placeholder uuid as no hardware id', function () {
        $placeholder = hash('sha256', AgentServer::salt().'03000200-0400-0500-0006-000700080009');
        $juan = agentUser(42);
        $juans = pairAgent($this, $juan, ['hardware_id_hash' => $placeholder])['device'];
        $maria = agentUser(7);

        // Another board with the same placeholder is not Juan's computer.
        $marias = pairAgent($this, $maria, ['hardware_id_hash' => $placeholder])['device'];

        expect($juans->fresh()->hardware_id_hash)->toBeNull()
            ->and($marias->fresh()->hardware_id_hash)->toBeNull()
            ->and($juans->fresh()->status)->toBe('active');
    });

    it('knows the same placeholder uuids as the agent', function () {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/placeholder-uuids.json'), true);

        expect(AgentPairingService::PLACEHOLDER_UUIDS)->toBe($fixture['uuids']);
    });

    it('does not count browser devices', function () {
        $juan = agentUser(42);
        signingDevice(42);

        expect(pairAgent($this, $juan)['poll']['status'])->toBe('confirmed');
    });
});

describe('device types', function () {

    it('stores the detected type and the owner\'s correction', function () {
        $agent = pairAgent($this, agentUser(), [
            'device_type' => 'mac_mini', 'form_factor' => 'desktop', 'chassis_type' => null, 'virtual' => false,
        ], 'mac_studio');

        $device = $agent['device']->fresh();
        expect($device->device_type)->toBe('mac_studio')
            ->and($device->detected_device_type)->toBe('mac_mini')
            ->and($device->virtual)->toBeFalse()
            ->and($device->deviceType()->label())->toBe('Mac Studio')
            ->and($agent['poll']['device']['device_type'])->toBe('mac_studio');
    });

    it('keeps the SMBIOS chassis type a Windows agent reports', function () {
        $agent = pairAgent($this, agentUser(), ['platform' => 'windows', 'device_type' => 'all_in_one', 'chassis_type' => 13]);

        expect($agent['device']->fresh())
            ->device_type->toBe('all_in_one')
            ->chassis_type->toBe(13);
    });

    it('ignores a correction that is not a catalogue value', function () {
        $agent = pairAgent($this, agentUser(), ['device_type' => 'mac_mini'], 'toaster');

        expect($agent['device']->fresh()->device_type)->toBe('mac_mini');
    });

    it('derives a type for agents from before device types', function () {
        $agent = pairAgent($this, agentUser());

        expect($agent['device']->fresh()->device_type)->toBe('laptop');
    });

    it('advertises the blocked types at lookup, virtual machines by default', function () {
        $user = agentUser();
        $code = fn () => app(AgentPairingService::class)->start($user->id)['user_code'];

        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $code()])
            ->assertJsonPath('blocked_device_types', ['virtual_machine']);

        config(['signature.devices.agent.blocked_device_types' => ['server', 'not_a_type']]);
        agentPost($this, '/signature/agent/pairings/lookup', ['user_code' => $code()])
            ->assertJsonPath('blocked_device_types', ['server']);
    });

    it('refuses a virtual machine by default, by type or by the firmware flag', function () {
        $user = agentUser();

        claimAgent($this, $user, ['device_type' => 'virtual_machine'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'device_type_not_allowed');

        claimAgent($this, $user, ['device_type' => 'laptop', 'virtual' => true])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'device_type_not_allowed');

        expect(SigningDevice::count())->toBe(0);
    });

    it('pairs a VM where allowed, and never lets the owner relabel it', function () {
        config(['signature.devices.agent.blocked_device_types' => []]);

        $agent = pairAgent($this, agentUser(), ['device_type' => 'virtual_machine', 'virtual' => true], 'laptop');

        expect($agent['device']->fresh())
            ->device_type->toBe('virtual_machine')
            ->detected_device_type->toBe('virtual_machine')
            ->virtual->toBeTrue();
    });

    it('refuses on confirm when the policy tightened after the claim', function () {
        config(['signature.devices.agent.blocked_device_types' => []]);
        $user = agentUser();
        $claim = claimAgent($this, $user, ['device_type' => 'virtual_machine', 'virtual' => true]);
        $claim['response']->assertOk();

        config(['signature.devices.agent.blocked_device_types' => ['virtual_machine']]);

        expect(fn () => app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $claim['lookup']['pairing'])->firstOrFail(), $user->id))
            ->toThrow(InvalidArgumentException::class, 'virtual machine');
    });
});

describe('releasing a computer', function () {

    it('frees the computer for another account, and audits it', function () {
        $juan = agentUser(42);
        $device = pairAgent($this, $juan)['device'];

        $this->artisan('signature:agent-release', ['device' => $device->uuid, '--force' => true])
            ->expectsOutputToContain('Released Juan’s MacBook Pro')
            ->assertSuccessful();

        expect($device->fresh()->status)->toBe('revoked')
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_RELEASED)->where('device_id', $device->id)->exists())->toBeTrue();

        $maria = agentUser(7);
        claimAgent($this, $maria)['response']->assertOk();
    });

    it('lists paired computers to find the one to release', function () {
        $device = pairAgent($this, agentUser(42))['device'];

        $this->artisan('signature:agent-release')
            ->expectsOutputToContain($device->uuid)
            ->assertSuccessful();
    });

    it('asks first, and does nothing on no', function () {
        $device = pairAgent($this, agentUser(42))['device'];

        $this->artisan('signature:agent-release', ['device' => $device->uuid])
            ->expectsConfirmation('Release Juan’s MacBook Pro (Computer laptop) from juan42@example.test? It will no longer be able to sign.', 'no')
            ->assertSuccessful();

        expect($device->fresh()->status)->toBe('active');
    });

    it('fails for an unknown device', function () {
        $this->artisan('signature:agent-release', ['device' => '00000000-0000-0000-0000-000000000000'])->assertFailed();
    });
});

describe('signing devices component: one per computer', function () {

    it("shows the account's other devices and saves the corrected type", function () {
        $juan = agentUser();
        signingDevice($juan->id);

        $component = \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)->call('startPairing');
        claimAgent($this, $juan, ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Office Mac', 'device_type' => 'mac_mini'], $component->get('userCode'))['response']
            ->assertOk();

        $component->call('refreshPairing')
            ->assertSee('Pair Office Mac?')
            ->assertSee('Your other signing devices')
            ->assertSee('Chrome on Mac (Computer desktop)')
            ->assertSet('pairDeviceType', 'mac_mini')
            ->set('pairDeviceType', 'mac_studio')
            ->call('confirmPairing')
            ->assertSee('Paired Office Mac');

        expect(SigningDevice::where('label', 'Office Mac')->value('device_type'))->toBe('mac_studio');
    });

    it('says before pairing that the account already has a computer', function () {
        $juan = agentUser();
        pairAgent($this, $juan);

        \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)
            ->assertSee('Your account is paired with')
            ->assertSee('You can only re-pair that computer')
            ->call('startPairing')
            ->assertSee('You can only re-pair that computer');
    });

    it('will not confirm a second computer that slipped past the claim', function () {
        $juan = agentUser();
        $component = \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)->call('startPairing');
        claimAgent($this, $juan, [], $component->get('userCode'))['response']->assertOk();
        signingDevice($juan->id, 'agent');

        $component->call('refreshPairing')
            ->assertSee('Your account is already paired with another computer')
            ->assertDontSee('Pair this computer');
    });

    it('offers to re-pair the same computer, and says so after', function () {
        $juan = agentUser();
        $device = pairAgent($this, $juan)['device'];

        $component = \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)->call('startPairing');
        claimAgent($this, $juan, [], $component->get('userCode'))['response']->assertOk();

        $component->call('refreshPairing')
            ->assertSee('Re-pair Juan’s MacBook Pro?')
            ->assertSee('its old keys will stop working')
            ->call('confirmPairing')
            ->assertSee('Re-paired Juan’s MacBook Pro with new keys');

        expect(SigningDevice::where('kind', 'agent')->count())->toBe(1)
            ->and($device->fresh()->rebound_at)->not->toBeNull();
    });

    it('flags a virtual machine paired before they were blocked', function () {
        config(['signature.devices.agent.blocked_device_types' => []]);
        $juan = agentUser();
        pairAgent($this, $juan, ['device_type' => 'virtual_machine', 'virtual' => true]);
        config(['signature.devices.agent.blocked_device_types' => ['virtual_machine']]);

        \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)
            ->assertSee('Virtual machine: no longer allowed for new pairings');
    });
});
