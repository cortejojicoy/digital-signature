<?php

namespace Kukux\DigitalSignature\Hub\OAuth;

use InvalidArgumentException;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubToken;

/**
 * Registers the apps that use the hub (performance, amp, sims…) and rotates
 * their secrets. Used by the admin Apps page and `signature:hub-app`.
 *
 * The client secret is shown once and stored only as its SHA-256; the webhook
 * secret has to be readable to sign webhooks, so it is stored encrypted (the
 * model's `encrypted` cast, i.e. APP_KEY).
 */
class HubAppRegistrar
{
    public const SCOPES = ['signatures.read', 'sign'];

    /**
     * @param  array<int, string>  $redirectUris  Exact URIs authorize may redirect to.
     * @param  array<int, string>  $scopes
     * @return array{app: HubApp, client_secret: string, webhook_secret: string}
     *
     * @throws InvalidArgumentException
     */
    public function create(
        string $clientId,
        string $name,
        array $redirectUris = [],
        ?string $webhookUrl = null,
        array $scopes = self::SCOPES,
    ): array {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $clientId)) {
            throw new InvalidArgumentException('The client id must be 1-64 lowercase letters, digits, "-" or "_".');
        }

        if (HubApp::query()->where('client_id', $clientId)->exists()) {
            throw new InvalidArgumentException("An app with client id [{$clientId}] already exists.");
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('The app needs a name.');
        }

        $unknown = array_diff($scopes, self::SCOPES);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown scope(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::SCOPES).'.');
        }

        foreach ($redirectUris as $uri) {
            $this->assertUrl($uri, 'redirect URI');
        }

        if ($webhookUrl !== null && $webhookUrl !== '') {
            $this->assertUrl($webhookUrl, 'webhook URL');
        }

        $clientSecret = self::secret();
        $webhookSecret = self::secret();

        $app = HubApp::create([
            'client_id'      => $clientId,
            'name'           => trim($name),
            'secret_hash'    => hash('sha256', $clientSecret),
            'webhook_url'    => $webhookUrl ?: null,
            'webhook_secret' => $webhookSecret,
            'redirect_uris'  => array_values(array_unique($redirectUris)),
            'scopes'         => array_values(array_unique($scopes)),
            // Each app's mirrors live under its own prefix on RustFS (A12).
            'mirror_prefix'  => $clientId,
            'active'         => true,
        ]);

        return ['app' => $app, 'client_secret' => $clientSecret, 'webhook_secret' => $webhookSecret];
    }

    /**
     * A new client secret. The old one stops working at once, and so do the
     * app's tokens: whoever held the old secret may have minted them.
     */
    public function rotateSecret(HubApp $app): string
    {
        $secret = self::secret();

        $app->forceFill(['secret_hash' => hash('sha256', $secret)])->save();

        HubToken::query()
            ->where('app_id', $app->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $secret;
    }

    public function rotateWebhookSecret(HubApp $app): string
    {
        $secret = self::secret();

        $app->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    /** 64 hex characters (256 bits). */
    public static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function assertUrl(string $url, string $what): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true) || ! $host) {
            throw new InvalidArgumentException("Invalid {$what}: [{$url}].");
        }

        // Plain http only for local development.
        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && ! str_ends_with($host, '.test')) {
            throw new InvalidArgumentException("The {$what} must use https: [{$url}].");
        }

        if (parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException("The {$what} must not have a #fragment: [{$url}].");
        }
    }
}
