<?php

namespace Kukux\DigitalSignature\Client;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubNotFoundException;
use Kukux\DigitalSignature\Client\Exceptions\HubRefusedException;
use Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException;
use Kukux\DigitalSignature\Client\Exceptions\SpecimenChangedException;

/**
 * Client mode: every call this app makes to the hub (docs/hub/contracts.md §2).
 *
 * App calls carry a client-credentials token, fetched once and cached until
 * shortly before it expires. A 401 drops the cached token and tries once more
 * with a fresh one, so a hub that rotated its tokens costs one round trip.
 *
 * **Circuit breaker (R1).** `hub.breaker.failures` consecutive connection
 * errors or 5xx answers open the breaker for `hub.breaker.cooldown` seconds:
 * every call fails fast with HubUnavailableException instead of each page
 * waiting out the timeout, and `hub.unavailable` is logged once when it opens.
 * The first call after the cool-down goes through; a success closes it.
 *
 * **Errors.** 409 `specimen_changed`, 404, and 422/403 map to their own
 * exceptions so callers branch on the type, not on a status code.
 */
class HubClient
{
    public const TOKEN_KEY = 'signature:hub:token';

    public const FAILURES_KEY = 'signature:hub:breaker:failures';

    public const OPEN_KEY = 'signature:hub:breaker:open';

    // -------------------------------------------------------------------------
    // OAuth (person sign-in)
    // -------------------------------------------------------------------------

    /**
     * Where to send the browser to sign in at the hub (authorization code +
     * PKCE S256).
     */
    public function authorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        return $this->url('signature/hub/oauth/authorize').'?'.http_build_query([
            'response_type'         => 'code',
            'client_id'             => (string) config('signature.hub.client_id'),
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, sub: string}
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->send(fn (PendingRequest $http) => $http->asForm()->post('signature/hub/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => (string) config('signature.hub.client_id'),
            'client_secret' => (string) config('signature.hub.client_secret'),
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]), authenticated: false)->json();
    }

    /**
     * @return array{sub: string, name: ?string, email: ?string, emp_no: ?string, unit: ?string, position: ?string}
     */
    public function userinfo(string $accessToken): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get('signature/hub/userinfo'),
            authenticated: false,
        )->json();
    }

    // -------------------------------------------------------------------------
    // People
    // -------------------------------------------------------------------------

    /** `{"status", "checks"}`, whatever the status code; never trips the breaker. */
    public function health(): array
    {
        try {
            $response = $this->http()->get('signature/hub/api/v1/health');
        } catch (ConnectionException) {
            return ['status' => 'unreachable', 'checks' => []];
        }

        return (array) $response->json() + ['status' => $response->successful() ? 'ok' : 'degraded'];
    }

    /**
     * Exact match on one identifier, or null.
     *
     * @return array{sub: string, name: ?string, email: ?string, emp_no: ?string, unit: ?string, position: ?string}|null
     */
    public function findPerson(?string $email = null, ?string $empNo = null): ?array
    {
        $query = array_filter(['emp_no' => $empNo, 'email' => $email], fn ($v) => filled($v));

        if ($query === []) {
            return null;
        }

        // One identifier per call: the hub matches exactly on what it is given.
        $query = array_slice($query, 0, 1, true);

        $data = $this->send(fn (PendingRequest $http) => $http->get('signature/hub/api/v1/people', $query))->json('data');

        return is_array($data) && isset($data[0]['sub']) ? $data[0] : null;
    }

    /** @throws HubNotFoundException `unknown_person` */
    public function person(string $sub): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->personPath($sub)))->json();
    }

    /** Mark this app as a holder: the person is a named signatory here. */
    public function link(string $sub): bool
    {
        return (bool) $this->send(fn (PendingRequest $http) => $http->post($this->personPath($sub).'/link'))->json('linked');
    }

    /**
     * @return array{uuid: string, status: string, image_sha256: string, certificate_fingerprint: ?string, updated_at: ?string}
     *
     * @throws HubNotFoundException `no_signature`
     */
    public function signature(string $sub): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->personPath($sub).'/signature'))->json();
    }

    /**
     * The person's signature PNG, or null when it still matches $etag (304).
     *
     * @return array{bytes: string, sha256: ?string, etag: ?string}|null
     */
    public function image(string $sub, ?string $etag = null): ?array
    {
        $response = $this->send(function (PendingRequest $http) use ($sub, $etag) {
            $http = $http->accept('image/png');

            if ($etag !== null && $etag !== '') {
                $http = $http->withHeaders(['If-None-Match' => '"'.$etag.'"']);
            }

            return $http->get($this->personPath($sub).'/signature/image');
        });

        if ($response->status() === 304) {
            return null;
        }

        return [
            'bytes'  => $response->body(),
            'sha256' => ($header = $response->header('X-Image-Sha256')) !== '' ? strtolower(trim($header)) : null,
            'etag'   => ($tag = trim($response->header('ETag'), " \"W/")) !== '' ? $tag : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Signing and certificates
    // -------------------------------------------------------------------------

    /**
     * @param  array{sub: string, document_hash: string, specimen_hash: string, title: string, slot?: ?string, capacity?: ?string, idempotency_key: string}  $request
     * @return array{id: string, status: string, approval_link: string, job_uuid: string, expires_at: string}
     *
     * @throws SpecimenChangedException
     * @throws HubRefusedException  not_verified, no_signature, certificate_revoked, separated, unknown_person
     */
    public function createSignRequest(array $request): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post(
            'signature/hub/api/v1/sign-requests',
            array_filter($request, fn ($v) => $v !== null),
        ))->json();
    }

    /**
     * @return array{id: string, status: string, refusal_reason?: string, cms?: string, certificate_fingerprint?: string, signed_at?: string}
     */
    public function signRequest(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('signature/hub/api/v1/sign-requests/'.rawurlencode($id)))->json();
    }

    /**
     * @return array{fingerprint: string, status: string, revoked_at?: ?string, subject?: ?string}
     */
    public function certificate(string $fingerprint): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('signature/hub/api/v1/certificates/'.rawurlencode($fingerprint)))->json();
    }

    // -------------------------------------------------------------------------
    // Token
    // -------------------------------------------------------------------------

    /** This app's client-credentials token, cached until shortly before expiry. */
    public function token(): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $body = $this->send(fn (PendingRequest $http) => $http->asForm()->post('signature/hub/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => (string) config('signature.hub.client_id'),
            'client_secret' => (string) config('signature.hub.client_secret'),
        ]), authenticated: false)->json();

        $token = (string) ($body['access_token'] ?? '');

        if ($token === '') {
            throw new HubException('The hub issued no access token.', 'invalid_token_response');
        }

        // A minute early, so a token never expires between the cache read and
        // the hub checking it.
        $ttl = max(1, (int) ($body['expires_in'] ?? 3600) - 60);

        Cache::put($this->tokenKey(), $token, $ttl);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    // -------------------------------------------------------------------------
    // Breaker
    // -------------------------------------------------------------------------

    public function isOpen(): bool
    {
        return Cache::has(self::OPEN_KEY);
    }

    public function reset(): void
    {
        Cache::forget(self::OPEN_KEY);
        Cache::forget(self::FAILURES_KEY);
    }

    protected function recordFailure(string $why): void
    {
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
        $threshold = max(1, (int) config('signature.hub.breaker.failures', 3));
        $cooldown = max(1, (int) config('signature.hub.breaker.cooldown', 60));

        // Kept a little longer than the cool-down, so the half-open call after
        // it re-opens the breaker straight away if the hub is still down.
        Cache::put(self::FAILURES_KEY, $failures, $cooldown * 2);

        if ($failures >= $threshold && ! $this->isOpen()) {
            Cache::put(self::OPEN_KEY, now()->addSeconds($cooldown)->getTimestamp(), $cooldown);

            Log::warning('hub.unavailable', [
                'hub'      => config('signature.hub.url'),
                'failures' => $failures,
                'cooldown' => $cooldown,
                'last'     => $why,
            ]);
        }
    }

    protected function recordSuccess(): void
    {
        if (Cache::has(self::FAILURES_KEY) || $this->isOpen()) {
            $this->reset();
        }
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * Run one call through the breaker, the token and the error mapping.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    protected function send(Closure $call, bool $authenticated = true): Response
    {
        if ($this->isOpen()) {
            throw new HubUnavailableException('The signature hub is unavailable. Try again in a minute.', 'unavailable');
        }

        $attempt = function () use ($call, $authenticated): Response {
            $http = $this->http();

            return $call($authenticated ? $http->withToken($this->token()) : $http);
        };

        try {
            $response = $attempt();

            if ($authenticated && $response->status() === 401) {
                $this->forgetToken();
                $response = $attempt();
            }
        } catch (ConnectionException $e) {
            $this->recordFailure($e->getMessage());

            throw new HubUnavailableException('The signature hub could not be reached.', 'unavailable', null, $e);
        }

        if ($response->serverError()) {
            $this->recordFailure('HTTP '.$response->status());

            throw new HubUnavailableException(
                is_string($response->json('message')) && $response->json('message') !== ''
                    ? $response->json('message')
                    : 'The signature hub is unavailable (HTTP '.$response->status().').',
                (string) ($response->json('error') ?? 'unavailable'),
                $response->status(),
            );
        }

        $this->recordSuccess();

        return $this->guard($response);
    }

    /** Map a 4xx to its exception; pass anything else through. */
    protected function guard(Response $response): Response
    {
        $status = $response->status();

        if ($status < 400) {
            return $response;
        }

        $code = is_string($response->json('error')) ? $response->json('error') : null;
        $message = is_string($response->json('message')) && $response->json('message') !== ''
            ? $response->json('message')
            : "The signature hub refused the request (HTTP {$status}).";

        throw match (true) {
            $status === 409 && $code === 'specimen_changed' => new SpecimenChangedException(
                $message,
                is_string($response->json('current_specimen_hash')) ? $response->json('current_specimen_hash') : null,
            ),
            $status === 404                  => new HubNotFoundException($message, $code ?? 'not_found', 404),
            in_array($status, [403, 422], true) => new HubRefusedException($message, $code, $status),
            default                          => new HubException($message, $code, $status),
        };
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->url(''))
            ->timeout(max(1, (int) config('signature.hub.timeout', 10)))
            ->acceptJson();
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('signature.hub.url'), '/').'/'.ltrim($path, '/');
    }

    protected function personPath(string $sub): string
    {
        return 'signature/hub/api/v1/people/'.rawurlencode($sub);
    }

    /** Per client id, so two apps sharing a cache store never share a token. */
    protected function tokenKey(): string
    {
        return self::TOKEN_KEY.':'.sha1((string) config('signature.hub.url').'|'.(string) config('signature.hub.client_id'));
    }
}
