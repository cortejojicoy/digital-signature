<?php

use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Hub\Identity\HubLoginService;
use Kukux\DigitalSignature\Models\HubLogin;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Kukux\DigitalSignature\Tests\Feature\Hub\HubIdentityTestCase;

/*
 * "Sign in with your computer" (plan 1.5, docs/hub/contracts.md §4): the
 * browser's challenge, the agent's usernameless claim, Touch ID approval
 * through the existing job endpoints, and the browser signed in.
 */

uses(HubIdentityTestCase::class);

const HUB_CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

beforeEach(function () {
    $this->hubPanels();

    $this->juan = $this->hubUser(42, 'Juan Dela Cruz');
    $this->identified($this->juan, $this->person('Juan Dela Cruz', '2004-0001'));
    $this->agent = $this->pairComputer($this->juan);
});

function hubLoginChallenge($test, string $browser = 'a'): array
{
    $challenge = $test->fromBrowser($browser, 'POST', '/signature/hub/login/challenges', [], ['HTTP_USER_AGENT' => HUB_CHROME_ON_MAC])
        ->assertCreated()->json();

    parse_str(parse_url($challenge['link'], PHP_URL_QUERY), $query);

    return $challenge + ['token' => $query['t'], 'server' => $query['s']];
}

function hubLoginApprove($test, array $agent, array $job): void
{
    $test->agentRequest($agent, 'POST', "/signature/agent/jobs/{$job['uuid']}/complete", [
        'proof' => $test->agentSignature($agent['identity'], DeviceProofVerifier::message('login', $job['nonce'], $job['user_id'], $job['payload_hash'])),
    ])->assertOk()->assertJsonPath('status', 'completed');
}

it('signs the browser in once the paired computer approves, usernameless', function () {
    $challenge = hubLoginChallenge($this);

    expect($challenge['match_code'])->toMatch('/^\d{2}-\d{2}$/')
        ->and($challenge['link'])->toStartWith("kukuxsign://login/{$challenge['uuid']}?t=")
        ->and($challenge['server'])->toBe(AgentServer::id());

    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertOk()->assertJsonPath('status', 'pending');

    // The computer's account is who signs in: the browser never named anyone.
    $job = $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])
        ->assertOk()
        ->assertJsonPath('purpose', 'login')
        ->assertJsonPath('status', 'claimed')
        ->assertJsonPath('user_id', '42')
        ->assertJsonPath('document.title', 'Sign in to UPLB Signature')
        ->assertJsonPath('login.match_code', $challenge['match_code'])
        ->assertJsonPath('login.browser', 'Chrome on macOS')
        ->json();

    $login = HubLogin::where('uuid', $challenge['uuid'])->first();
    expect($job['payload_hash'])->toBe(HubLoginService::payloadHash($login))
        ->and($login->status)->toBe('claimed');

    hubLoginApprove($this, $this->agent, $job);

    // Another browser can't take the approval.
    $this->fromBrowser('b', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertNotFound();

    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")
        ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('redirect', 'http://localhost');

    expect($this->browserUserId('a'))->toBe(42)
        ->and($this->browserUserId('b'))->toBeNull()
        ->and($login->fresh()->status)->toBe('consumed')
        ->and(SignatureAudit::where('event', SignatureAudit::LOGIN_APPROVED)->where('personnel_key', 'p-juan-dela-cruz')->first()->context)
        ->toMatchArray(['purpose' => 'login', 'browser' => 'Chrome on macOS']);

    // Spent: a second poll signs nobody in.
    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertJsonPath('status', 'consumed');
});

it('refuses a wrong or reused link token, and an unknown challenge', function () {
    $challenge = hubLoginChallenge($this);

    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => str_repeat('x', 43)])
        ->assertStatus(403)->assertJsonPath('error.code', 'invalid_link_token');

    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])->assertOk();

    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])
        ->assertStatus(409)->assertJsonPath('error.code', 'login_unavailable');

    $this->agentRequest($this->agent, 'POST', '/signature/agent/logins/'.Illuminate\Support\Str::uuid().'/claim', ['link_token' => $challenge['token']])
        ->assertNotFound()->assertJsonPath('error.code', 'login_not_found');
});

it('refuses an expired challenge', function () {
    $challenge = hubLoginChallenge($this);
    $this->travel(config('signature.hub.login_ttl') + 1)->seconds();

    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])
        ->assertStatus(409)->assertJsonPath('error.code', 'login_unavailable');
});

it('refuses a proof over another browser\'s challenge (wrong match code or session)', function () {
    $mine = hubLoginChallenge($this, 'a');
    $theirs = hubLoginChallenge($this, 'b');

    $job = $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$mine['uuid']}/claim", ['link_token' => $mine['token']])->json();
    $other = HubLogin::where('uuid', $theirs['uuid'])->first();

    // Signed over browser b's challenge: doesn't verify for this job.
    $this->agentRequest($this->agent, 'POST', "/signature/agent/jobs/{$job['uuid']}/complete", [
        'proof' => $this->agentSignature($this->agent['identity'], DeviceProofVerifier::message('login', $job['nonce'], $job['user_id'], HubLoginService::payloadHash($other))),
    ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_proof');

    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$mine['uuid']}")->assertJsonPath('status', 'claimed');
    expect($this->browserUserId('a'))->toBeNull();
});

it('records a decline on the computer and signs nobody in', function () {
    $challenge = hubLoginChallenge($this);
    $job = $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])->json();

    $this->agentRequest($this->agent, 'POST', "/signature/agent/jobs/{$job['uuid']}/reject", ['reason' => 'declined'])->assertOk();

    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertJsonPath('status', 'rejected');

    expect($this->browserUserId('a'))->toBeNull()
        ->and(SignatureAudit::where('event', SignatureAudit::LOGIN_REJECTED)->exists())->toBeTrue();
});

it('refuses a revoked computer and a retired, separated or rejected account', function () {
    $challenge = hubLoginChallenge($this);

    Identity::forUser(42)->update(['status' => Identity::RETIRED]);
    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])
        ->assertStatus(409)->assertJsonPath('error.code', 'login_unavailable');

    Identity::forUser(42)->update(['status' => Identity::VERIFIED]);
    app(DeviceRegistry::class)->revoke($this->agent['device']->fresh());
    $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])
        ->assertUnauthorized();
});

it('sends a super_admin to the admin panel, and an intended URL wins over both', function () {
    $this->juan->update(['roles' => 'super_admin']);

    $challenge = hubLoginChallenge($this);
    $job = $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])->json();
    hubLoginApprove($this, $this->agent, $job);

    $this->fromBrowser('a', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertJsonPath('redirect', 'http://localhost/admin');

    // An app's /oauth/authorize sent this browser here: it goes back there.
    $this->fromBrowser('c', 'GET', '/admin/pending-claims');   // any guest hit; then set the intended URL
    $intended = 'http://localhost/signature/hub/oauth/authorize?client_id=performance&state=xyz';
    $handler = app('session')->driver()->getHandler();
    $id = $this->browserSessions['c'];
    $handler->write($id, serialize(array_merge(unserialize($handler->read($id)) ?: [], ['url' => ['intended' => $intended]])));

    $challenge = hubLoginChallenge($this, 'c');
    $job = $this->agentRequest($this->agent, 'POST', "/signature/agent/logins/{$challenge['uuid']}/claim", ['link_token' => $challenge['token']])->json();
    hubLoginApprove($this, $this->agent, $job);

    $this->fromBrowser('c', 'GET', "/signature/hub/login/challenges/{$challenge['uuid']}")->assertJsonPath('redirect', $intended);
});
