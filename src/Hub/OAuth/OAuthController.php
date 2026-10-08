<?php

namespace Kukux\DigitalSignature\Hub\OAuth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Kukux\DigitalSignature\Hub\Api\HubApiException;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Hub\Api\ValidatesInput;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubHolder;

/**
 * OAuth for apps (docs/hub/contracts.md §2.1):
 *
 *   POST /signature/hub/oauth/token       client_credentials | authorization_code (+PKCE)
 *   GET  /signature/hub/oauth/authorize   browser; needs a hub session and a verified identity
 *   GET  /signature/hub/userinfo          person token
 *
 * The hub's sign-in is the agent (Touch ID / Windows Hello), so authorize
 * relies on the hub's web session that agent login established. Only a
 * verified identity gets a code: an unverified claim could be anyone (R8).
 */
class OAuthController extends Controller
{
    use ValidatesInput;

    /** RFC 7636 §4.1: 43-128 unreserved characters. Challenges share the alphabet. */
    private const PKCE = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public function __construct(
        private readonly HubOAuthServer $oauth,
        private readonly People $people,
    ) {}

    public function token(Request $request): JsonResponse
    {
        $grant = $request->input('grant_type');

        if (! is_string($grant) || $grant === '') {
            throw new HubApiException(400, 'invalid_request', 'grant_type is required.');
        }

        if (! in_array($grant, ['client_credentials', 'authorization_code'], true)) {
            throw new HubApiException(400, 'unsupported_grant_type', 'grant_type must be client_credentials or authorization_code.');
        }

        $app = $this->oauth->authenticateClient(...$this->clientCredentials($request));

        $body = $grant === 'client_credentials'
            ? $this->oauth->issueAppToken($app)
            : $this->exchangeCode($request, $app);

        // RFC 6749 §5.1: token responses are never cached.
        return response()->json($body, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function authorize(Request $request): RedirectResponse
    {
        // Client and redirect URI first, and never redirect to an
        // unregistered URI: that would make the hub an open redirector.
        $app = is_string($request->query('client_id'))
            ? HubApp::query()->where('client_id', $request->query('client_id'))->where('active', true)->first()
            : null;

        abort_if($app === null, 400, 'Unknown or disabled client_id.');

        $redirectUri = $request->query('redirect_uri');

        abort_unless(is_string($redirectUri) && $app->allowsRedirect($redirectUri), 400, 'redirect_uri is not registered for this app.');

        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(Route::has('signature.hub.landing') ? route('signature.hub.landing') : url('/'));
        }

        $personnelKey = $this->people->personnelKeyFor((int) $user->getAuthIdentifier());

        abort_unless(
            $personnelKey !== null && $this->people->isVerified((int) $user->getAuthIdentifier()),
            403,
            'Your identity has not been verified yet, so you cannot sign in to other apps. An administrator will verify your claim.',
        );

        $state = $request->query('state');
        $state = is_string($state) ? $state : null;

        if ($state !== null && strlen($state) > 512) {
            return $this->redirectWithError($redirectUri, 'invalid_request', 'state is too long.', null);
        }

        if ($request->query('response_type') !== 'code') {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', 'response_type must be code.', $state);
        }

        $challenge = $request->query('code_challenge');

        if (! is_string($challenge) || ! preg_match(self::PKCE, $challenge) || $request->query('code_challenge_method') !== 'S256') {
            return $this->redirectWithError($redirectUri, 'invalid_request', 'PKCE is required: code_challenge with code_challenge_method=S256.', $state);
        }

        // Signing in to an app makes it a holder: it may pull this person's
        // specimen and gets their webhooks.
        HubHolder::link($app->id, $personnelKey);

        $code = $this->oauth->issueCode($app, (int) $user->getAuthIdentifier(), $redirectUri, $challenge);

        return redirect()->away($this->withQuery($redirectUri, array_filter(
            ['code' => $code, 'state' => $state],
            fn ($v) => $v !== null,
        )));
    }

    public function userinfo(Request $request): JsonResponse
    {
        $userId = (int) AuthenticateHubToken::token($request)->user_id;
        $key = $this->people->personnelKeyFor($userId);

        if ($key === null || ! $this->people->isVerified($userId)) {
            throw new HubApiException(403, 'not_verified', 'This account is no longer linked to a verified person.');
        }

        $person = $this->people->find($key);

        if ($person === null) {
            throw new HubApiException(404, 'unknown_person', 'This person is not in the personnel directory.');
        }

        return response()->json($this->people->claims($person));
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(Request $request, HubApp $app): array
    {
        $input = $this->validated($request, [
            'code'          => ['required', 'string', 'max:256'],
            'redirect_uri'  => ['required', 'string', 'max:2048'],
            'code_verifier' => ['required', 'string', 'regex:'.self::PKCE],
        ], status: 400);

        $code = $this->oauth->redeemCode($app, $input['code'], $input['redirect_uri'], $input['code_verifier']);

        // Still the person's current, verified account? A transfer, rejection
        // or separation between authorize and exchange voids the code.
        $userId = (int) $code->user_id;
        $key = $this->people->personnelKeyFor($userId);

        if ($key === null || ! $this->people->isVerified($userId)) {
            throw new HubApiException(400, 'invalid_grant', 'This account is no longer linked to a verified person.');
        }

        return $this->oauth->issuePersonToken($app, $userId) + ['sub' => $key];
    }

    /**
     * client_id / client_secret from the body, or HTTP Basic (RFC 6749 §2.3.1,
     * whose values are form-encoded).
     *
     * @return array{0: string, 1: string}
     */
    private function clientCredentials(Request $request): array
    {
        if ($request->getUser() !== null && $request->getUser() !== '') {
            return [urldecode((string) $request->getUser()), urldecode((string) $request->getPassword())];
        }

        $id = $request->input('client_id');
        $secret = $request->input('client_secret');

        if (! is_string($id) || ! is_string($secret) || $id === '' || $secret === '') {
            throw new HubApiException(401, 'invalid_client', 'client_id and client_secret are required.');
        }

        return [$id, $secret];
    }

    private function redirectWithError(string $redirectUri, string $error, string $description, ?string $state): RedirectResponse
    {
        return redirect()->away($this->withQuery($redirectUri, array_filter([
            'error'             => $error,
            'error_description' => $description,
            'state'             => $state,
        ], fn ($v) => $v !== null)));
    }

    /** @param  array<string, string>  $params */
    private function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
