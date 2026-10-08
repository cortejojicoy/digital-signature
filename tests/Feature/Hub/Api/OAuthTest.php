<?php

use Kukux\DigitalSignature\Hub\OAuth\HubOAuthServer;
use Kukux\DigitalSignature\Models\HubCode;
use Kukux\DigitalSignature\Models\HubHolder;
use Kukux\DigitalSignature\Models\HubToken;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Tests\Feature\Hub\Api\HubApiEnvironment;

uses(HubApiEnvironment::class);

beforeEach(fn () => $this->setUpHubApi());

/*
 * OAuth for apps, without Passport (docs/hub/contracts.md §2.1).
 */

const HUB_REDIRECT = 'https://performance.uplb.test/signature/hub/callback';

function pkcePair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

    return [$verifier, HubOAuthServer::challengeFor($verifier)];
}

/** Sign in at the hub and authorize; returns [code, verifier, response]. */
function authorizeAs($test, $user, array $overrides = []): array
{
    [$verifier, $challenge] = pkcePair();

    $response = $test->actingAs($user)->get('/signature/hub/oauth/authorize?'.http_build_query(array_merge([
        'response_type'         => 'code',
        'client_id'             => 'performance',
        'redirect_uri'          => HUB_REDIRECT,
        'state'                 => 'xyz-state',
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ], $overrides)));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return [$query['code'] ?? null, $verifier, $response];
}

// ── client_credentials ───────────────────────────────────────────────────

it('issues an app token for valid client credentials and stores only its hash', function () {
    $app = $this->registerApp();

    $response = $this->postJson('/signature/hub/oauth/token', [
        'grant_type'    => 'client_credentials',
        'client_id'     => 'performance',
        'client_secret' => $app['client_secret'],
    ])->assertOk();

    $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'scope'])
        ->assertJson(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'signatures.read sign']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $token = $response->json('access_token');
    expect(strlen($token))->toBeGreaterThanOrEqual(40)
        ->and(HubToken::query()->where('token_hash', hash('sha256', $token))->exists())->toBeTrue()
        ->and(HubToken::query()->where('token_hash', $token)->exists())->toBeFalse();
});

it('accepts client credentials over HTTP Basic', function () {
    $app = $this->registerApp();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('performance:'.$app['client_secret'])])
        ->postJson('/signature/hub/oauth/token', ['grant_type' => 'client_credentials'])
        ->assertOk();
});

it('refuses a wrong secret, an unknown client and a disabled app alike', function () {
    $app = $this->registerApp();

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => 'performance', 'client_secret' => 'nope',
    ])->assertStatus(401)->assertExactJson(['error' => 'invalid_client', 'message' => 'Unknown client, wrong secret, or the app is disabled.']);

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => 'nobody', 'client_secret' => $app['client_secret'],
    ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);

    $app['app']->update(['active' => false]);

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => 'performance', 'client_secret' => $app['client_secret'],
    ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);

    expect(HubToken::count())->toBe(0);
});

it('rejects unsupported grant types', function () {
    $this->postJson('/signature/hub/oauth/token', ['grant_type' => 'password'])
        ->assertStatus(400)->assertJson(['error' => 'unsupported_grant_type']);

    $this->postJson('/signature/hub/oauth/token', [])
        ->assertStatus(400)->assertJson(['error' => 'invalid_request']);
});

it('stops honouring tokens after the client secret is rotated', function () {
    $app = $this->registerApp();
    $this->person();
    $token = $this->appToken($app['app']);

    $this->getJson('/signature/hub/api/v1/people/p-juan', $this->bearer($token))->assertOk();

    app(\Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar::class)->rotateSecret($app['app']);

    $this->getJson('/signature/hub/api/v1/people/p-juan', $this->bearer($token))
        ->assertStatus(401)->assertJson(['error' => 'invalid_token']);
});

// ── authorize ────────────────────────────────────────────────────────────

it('sends a guest to the hub landing and keeps the authorize URL as intended', function () {
    $this->registerApp();
    [, $challenge] = pkcePair();

    $url = '/signature/hub/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => 'performance', 'redirect_uri' => HUB_REDIRECT,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]);

    // The identity work's landing, which forwards to the person panel's
    // sign-in without touching url.intended.
    $this->get($url)->assertRedirect(route('signature.hub.landing'));

    expect(session('url.intended'))->toStartWith(url('/signature/hub/oauth/authorize?'))
        ->toContain('code_challenge='.$challenge)
        ->toContain('client_id=performance');
});

it('refuses an unverified identity with a 403 page', function () {
    $this->registerApp();
    $this->person();
    $user = $this->account(status: Identity::PENDING);

    [$code, , $response] = authorizeAs($this, $user);

    $response->assertForbidden();
    expect($code)->toBeNull()->and(HubCode::count())->toBe(0);
});

it('never redirects to an unregistered redirect_uri or for an unknown client', function () {
    $this->registerApp();
    $this->person();
    $user = $this->account();

    [, , $response] = authorizeAs($this, $user, ['redirect_uri' => 'https://evil.test/callback']);
    $response->assertStatus(400);

    [, , $response] = authorizeAs($this, $user, ['redirect_uri' => HUB_REDIRECT.'/extra']);
    $response->assertStatus(400);

    [, , $response] = authorizeAs($this, $user, ['client_id' => 'unknown']);
    $response->assertStatus(400);
});

it('redirects back with an error when PKCE is missing or not S256', function () {
    $this->registerApp();
    $this->person();
    $user = $this->account();

    [, , $response] = authorizeAs($this, $user, ['code_challenge_method' => 'plain']);

    expect($response->headers->get('Location'))->toStartWith(HUB_REDIRECT.'?')
        ->toContain('error=invalid_request')
        ->toContain('state=xyz-state');
});

it('runs the authorization code + PKCE flow and links the app as a holder', function () {
    $app = $this->registerApp();
    $this->person();
    $user = $this->account();

    [$code, $verifier, $response] = authorizeAs($this, $user);

    expect($response->headers->get('Location'))->toStartWith(HUB_REDIRECT.'?code=')->toContain('state=xyz-state')
        ->and(HubHolder::query()->where('app_id', $app['app']->id)->where('personnel_key', 'p-juan')->exists())->toBeTrue()
        ->and(HubCode::query()->where('code_hash', hash('sha256', $code))->exists())->toBeTrue();

    auth()->logout();

    $token = $this->postJson('/signature/hub/oauth/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => 'performance',
        'client_secret' => $app['client_secret'],
        'code'          => $code,
        'redirect_uri'  => HUB_REDIRECT,
        'code_verifier' => $verifier,
    ])->assertOk()
        ->assertJson(['token_type' => 'Bearer', 'sub' => 'p-juan'])
        ->json('access_token');

    // userinfo: the person token, and only the contract's claims.
    $this->getJson('/signature/hub/userinfo', $this->bearer($token))
        ->assertOk()
        ->assertExactJson([
            'sub' => 'p-juan', 'name' => 'Juan dela Cruz', 'email' => 'jdcruz@up.edu.ph',
            'emp_no' => 'E-1001', 'unit' => 'ICS', 'position' => 'Assistant Professor',
        ]);

    // A person token is not an app token, and vice versa.
    $this->getJson('/signature/hub/api/v1/people/p-juan', $this->bearer($token))->assertStatus(401);
    $this->getJson('/signature/hub/userinfo', $this->bearer($this->appToken($app['app'])))->assertStatus(401);
});

it('fails the exchange when the PKCE verifier does not match', function () {
    $app = $this->registerApp();
    $this->person();
    [$code] = authorizeAs($this, $this->account());

    [$otherVerifier] = pkcePair();

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => 'performance', 'client_secret' => $app['client_secret'],
        'code' => $code, 'redirect_uri' => HUB_REDIRECT, 'code_verifier' => $otherVerifier,
    ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});

it('uses a code once, and not after it expires', function () {
    $app = $this->registerApp();
    $this->person();
    $user = $this->account();

    $exchange = fn (string $code, string $verifier) => $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => 'performance', 'client_secret' => $app['client_secret'],
        'code' => $code, 'redirect_uri' => HUB_REDIRECT, 'code_verifier' => $verifier,
    ]);

    [$code, $verifier] = authorizeAs($this, $user);
    $exchange($code, $verifier)->assertOk();
    $exchange($code, $verifier)->assertStatus(400)->assertJson(['error' => 'invalid_grant', 'message' => 'This authorization code was already used.']);

    [$code, $verifier] = authorizeAs($this, $user);
    $this->travel(61)->seconds();
    $exchange($code, $verifier)->assertStatus(400)->assertJson(['error' => 'invalid_grant', 'message' => 'This authorization code has expired.']);
});

it('refuses a code from another app or for another redirect_uri', function () {
    $performance = $this->registerApp();
    $amp = $this->registerApp('amp');
    $this->person();
    [$code, $verifier] = authorizeAs($this, $this->account());

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => 'amp', 'client_secret' => $amp['client_secret'],
        'code' => $code, 'redirect_uri' => HUB_REDIRECT, 'code_verifier' => $verifier,
    ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

    $this->postJson('/signature/hub/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => 'performance', 'client_secret' => $performance['client_secret'],
        'code' => $code, 'redirect_uri' => 'https://performance.uplb.test/other', 'code_verifier' => $verifier,
    ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});

it('expires app tokens after the configured lifetime', function () {
    $app = $this->registerApp();
    $this->person();
    $token = $this->appToken($app['app']);

    $this->travel(3601)->seconds();

    $this->getJson('/signature/hub/api/v1/people/p-juan', $this->bearer($token))->assertStatus(401);
});

it('requires the scope on both the token and the app', function () {
    $app = $this->registerApp(scopes: ['signatures.read']);
    $this->signer();
    $token = $this->appToken($app['app']);

    $this->postJson('/signature/hub/api/v1/sign-requests', [], $this->bearer($token))
        ->assertForbidden()->assertJson(['error' => 'insufficient_scope']);
});
