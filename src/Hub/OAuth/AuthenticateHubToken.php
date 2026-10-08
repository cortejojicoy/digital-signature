<?php

namespace Kukux\DigitalSignature\Hub\OAuth;

use Closure;
use Illuminate\Http\Request;
use Kukux\DigitalSignature\Hub\Api\HubApiException;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the hub API.
 *
 *   AuthenticateHubToken::class.':app,signatures.read'   an app token with that scope
 *   AuthenticateHubToken::class.':person'                a person token (userinfo)
 *
 * A scope must be on the token *and* still on the app, so removing a scope
 * from an app takes effect before its tokens expire. The app and token are
 * left on the request for the controllers: see app() / token().
 */
class AuthenticateHubToken
{
    public const APP = 'signature.hub_app';

    public const TOKEN = 'signature.hub_token';

    public function __construct(private readonly HubOAuthServer $oauth) {}

    public function handle(Request $request, Closure $next, string $kind = 'app', string ...$scopes): Response
    {
        $token = $this->oauth->tokenFor((string) $request->bearerToken());

        $wrongKind = $token !== null && ($kind === 'person') !== ($token->user_id !== null);

        if ($token === null || $wrongKind) {
            throw new HubApiException(401, 'invalid_token', $kind === 'person'
                ? 'A valid person access token is required.'
                : 'A valid app access token is required.', headers: [
                    'WWW-Authenticate' => 'Bearer realm="signature-hub", error="invalid_token"',
                ]);
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope, (array) ($token->scopes ?? []), true) || ($kind === 'app' && ! $token->app->hasScope($scope))) {
                throw new HubApiException(403, 'insufficient_scope', "This token lacks the [{$scope}] scope.", headers: [
                    'WWW-Authenticate' => "Bearer realm=\"signature-hub\", error=\"insufficient_scope\", scope=\"{$scope}\"",
                ]);
            }
        }

        $request->attributes->set(self::TOKEN, $token);
        $request->attributes->set(self::APP, $token->app);

        return $next($request);
    }

    public static function app(Request $request): HubApp
    {
        return $request->attributes->get(self::APP);
    }

    public static function token(Request $request): HubToken
    {
        return $request->attributes->get(self::TOKEN);
    }
}
