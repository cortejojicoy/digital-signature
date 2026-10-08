<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Client\Http\HubLoginController;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * "Sign in with UPLB Signature": authorization code + PKCE, then the local
 * user found by hub link, by email once, or created.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

/** Start a sign-in, and return the state and verifier the session holds. */
function startHubLogin($test): array
{
    $response = $test->get('/signature/hub/login');
    $response->assertRedirect();

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return ['query' => $query, 'location' => $response->headers->get('Location'), 'session' => session(HubLoginController::SESSION_KEY)];
}

function fakeHubSignIn($test, array $userinfo): void
{
    $test->fakeHub([
        'hub.test/signature/hub/oauth/token' => Http::response(['access_token' => 'person-token', 'token_type' => 'Bearer', 'expires_in' => 3600, 'sub' => $userinfo['sub']]),
        'hub.test/signature/hub/userinfo'    => Http::response($userinfo),
        'hub.test/signature/hub/api/v1/people/*/signature' => Http::response(['error' => 'no_signature', 'message' => 'None.'], 404),
    ]);
}

describe('hub sign-in', function () {

    it('redirects to the hub with state and an S256 PKCE challenge', function () {
        $start = startHubLogin($this);

        expect($start['location'])->toStartWith('https://hub.test/signature/hub/oauth/authorize?')
            ->and($start['query'])->toMatchArray([
                'response_type'         => 'code',
                'client_id'             => 'performance',
                'redirect_uri'          => route('signature.hub.callback'),
                'code_challenge_method' => 'S256',
                'state'                 => $start['session']['state'],
            ])
            ->and($start['query']['code_challenge'])->toBe(HubLoginController::challenge($start['session']['verifier']))
            ->and(strlen($start['query']['code_challenge']))->toBe(43);
    });

    it('refuses a callback whose state does not match', function () {
        startHubLogin($this);
        Http::fake();

        $this->get('/signature/hub/callback?code=abc&state=forged')->assertForbidden();

        Http::assertNothingSent();
        $this->assertGuest();
    });

    it('links an existing user by email once, and by sub from then on', function () {
        $user = makeUser(21, 'Maria Santos', 'MSantos@up.edu.ph');
        $start = startHubLogin($this);
        fakeHubSignIn($this, ['sub' => 'p-maria', 'name' => 'Maria Santos', 'email' => 'msantos@up.edu.ph', 'emp_no' => 'E-2']);

        $this->get('/signature/hub/callback?code=the-code&state='.$start['query']['state'])->assertRedirect();

        $this->assertAuthenticatedAs(TestUser::find($user->id));
        expect(HubAccount::userIdFor('p-maria'))->toBe(21)
            ->and(HubAccount::query()->where('sub', 'p-maria')->first()->claims['emp_no'])->toBe('E-2');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'the-code'
            && $r['code_verifier'] === $start['session']['verifier']
            && $r['redirect_uri'] === route('signature.hub.callback'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/userinfo') && $r->hasHeader('Authorization', 'Bearer person-token'));

        // The email changes at the hub; the link holds by sub.
        auth()->logout();
        $start = startHubLogin($this);
        fakeHubSignIn($this, ['sub' => 'p-maria', 'name' => 'Maria Santos', 'email' => 'maria.new@up.edu.ph']);
        $this->get('/signature/hub/callback?code=c2&state='.$start['query']['state']);

        $this->assertAuthenticatedAs(TestUser::find(21));
        expect(TestUser::query()->count())->toBe(1);
    });

    it('creates a user when nobody matches, and pulls their signature', function () {
        $png = stampablePng();
        $start = startHubLogin($this);
        $this->fakeHub([
            'hub.test/signature/hub/oauth/token' => Http::response(['access_token' => 'person-token', 'expires_in' => 3600, 'sub' => 'p-new']),
            'hub.test/signature/hub/userinfo'    => Http::response(['sub' => 'p-new', 'name' => 'Pedro Penduko', 'email' => 'pedro@up.edu.ph']),
        ] + $this->hubSignatureRoutes('p-new', $png));

        $this->get('/signature/hub/callback?code=c&state='.$start['query']['state'])->assertRedirect();

        $user = TestUser::query()->where('email', 'pedro@up.edu.ph')->first();

        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('Pedro Penduko')
            ->and(HubAccount::userIdFor('p-new'))->toBe($user->id)
            ->and(Signature::query()->where('user_id', $user->id)->where('source', 'hub')->value('hub_image_hash'))->toBe(hash('sha256', $png));
        $this->assertAuthenticatedAs($user);
    });

    it('refuses an unknown person when creating users is off', function () {
        config()->set('signature.hub.create_users', false);
        $start = startHubLogin($this);
        fakeHubSignIn($this, ['sub' => 'p-x', 'name' => 'X', 'email' => 'x@up.edu.ph']);

        $this->get('/signature/hub/callback?code=c&state='.$start['query']['state'])->assertForbidden();

        expect(TestUser::query()->count())->toBe(0);
    });

    it('never re-points an account already linked to another hub person', function () {
        $user = makeUser(22, 'Ana Cruz', 'ana@up.edu.ph');
        HubAccount::create(['user_id' => $user->id, 'sub' => 'p-ana', 'linked_at' => now()]);
        $start = startHubLogin($this);
        fakeHubSignIn($this, ['sub' => 'p-impostor', 'email' => 'ana@up.edu.ph']);

        $this->get('/signature/hub/callback?code=c&state='.$start['query']['state'])->assertForbidden();
        $this->assertGuest();
    });
});

it('puts "Sign in with UPLB Signature" under the Filament login form', function () {
    $html = (string) \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER);

    expect($html)->toContain('Sign in with UPLB Signature')
        ->and($html)->toContain(route('signature.hub.login'));
});
