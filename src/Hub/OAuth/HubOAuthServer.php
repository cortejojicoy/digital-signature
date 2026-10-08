<?php

namespace Kukux\DigitalSignature\Hub\OAuth;

use Illuminate\Support\Facades\DB;
use Kukux\DigitalSignature\Hub\Api\HubApiException;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubCode;
use Kukux\DigitalSignature\Models\HubToken;

/**
 * The hub's own OAuth 2 server, without Passport (docs/hub/contracts.md §2.1):
 *
 *   client_credentials            an app's token for the hub API
 *   authorization_code + PKCE     a person's token (sign-in to an app)
 *
 * Every secret it hands out (tokens, codes) is opaque random text stored only
 * as its SHA-256, so a database dump holds nothing usable.
 */
class HubOAuthServer
{
    /** Scopes a person token carries: who they are, nothing more. */
    public const PERSON_SCOPES = ['userinfo'];

    /**
     * The app these credentials belong to, checked in constant time. An
     * unknown client id costs the same hash comparison as a wrong secret, so
     * response timing doesn't reveal which client ids exist.
     *
     * @throws HubApiException
     */
    public function authenticateClient(string $clientId, string $clientSecret): HubApp
    {
        $app = HubApp::query()->where('client_id', $clientId)->first();

        $expected = $app?->secret_hash ?? str_repeat('0', 64);
        $matches = hash_equals($expected, hash('sha256', $clientSecret));

        if ($app === null || ! $matches || ! $app->active || $clientSecret === '') {
            throw new HubApiException(401, 'invalid_client', 'Unknown client, wrong secret, or the app is disabled.', headers: [
                'WWW-Authenticate' => 'Basic realm="signature-hub"',
            ]);
        }

        return $app;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function issueAppToken(HubApp $app): array
    {
        $scopes = array_values((array) ($app->scopes ?? []));

        return $this->issue($app, null, $scopes) + ['scope' => implode(' ', $scopes)];
    }

    /**
     * A one-time authorization code for a signed-in, verified person.
     */
    public function issueCode(HubApp $app, int $userId, string $redirectUri, string $codeChallenge): string
    {
        $code = self::random();

        HubCode::create([
            'code_hash'      => hash('sha256', $code),
            'app_id'         => $app->id,
            'user_id'        => $userId,
            'redirect_uri'   => $redirectUri,
            'code_challenge' => $codeChallenge,
            'expires_at'     => now()->addSeconds((int) config('signature.hub.code_ttl', 60)),
        ]);

        return $code;
    }

    /**
     * Spend a code: same app, same redirect URI, unexpired, unused, and the
     * PKCE verifier hashes to the challenge. Used exactly once, even when two
     * exchanges race (the conditional update decides).
     *
     * @return HubCode  the spent code (its user_id is the person)
     *
     * @throws HubApiException
     */
    public function redeemCode(HubApp $app, string $code, string $redirectUri, string $codeVerifier): HubCode
    {
        $row = HubCode::query()->where('code_hash', hash('sha256', $code))->first();

        $invalid = fn (string $why) => new HubApiException(400, 'invalid_grant', $why);

        if ($row === null || (int) $row->app_id !== (int) $app->id) {
            throw $invalid('Unknown authorization code.');
        }

        if ($row->used_at !== null) {
            throw $invalid('This authorization code was already used.');
        }

        if ($row->expires_at->isPast()) {
            throw $invalid('This authorization code has expired.');
        }

        if (! hash_equals($row->redirect_uri, $redirectUri)) {
            throw $invalid('redirect_uri does not match the one the code was issued for.');
        }

        if (! hash_equals($row->code_challenge, self::challengeFor($codeVerifier))) {
            throw $invalid('PKCE verification failed: code_verifier does not match code_challenge.');
        }

        $spent = DB::table($row->getTable())
            ->where('id', $row->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);

        if ($spent !== 1) {
            throw $invalid('This authorization code was already used.');
        }

        return $row->refresh();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function issuePersonToken(HubApp $app, int $userId): array
    {
        return $this->issue($app, $userId, self::PERSON_SCOPES);
    }

    /** The usable token behind a bearer string, or null. */
    public function tokenFor(string $bearer): ?HubToken
    {
        if ($bearer === '') {
            return null;
        }

        $token = HubToken::query()->where('token_hash', hash('sha256', $bearer))->with('app')->first();

        return $token !== null && $token->isUsable() && $token->app?->active ? $token : null;
    }

    /** RFC 7636 S256: BASE64URL(SHA256(verifier)), unpadded. */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** 48 random bytes, base64url: 64 characters. */
    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    private function issue(HubApp $app, ?int $userId, array $scopes): array
    {
        $token = self::random();
        $ttl = (int) config('signature.hub.token_ttl', 3600);

        HubToken::create([
            'token_hash' => hash('sha256', $token),
            'app_id'     => $app->id,
            'user_id'    => $userId,
            'scopes'     => $scopes,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl];
    }
}
