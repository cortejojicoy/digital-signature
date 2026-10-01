<?php

namespace Kukux\DigitalSignature\Agent;

/**
 * How this installation identifies itself to a paired desktop agent.
 *
 * `id` appears in every job link (`s=`) and `salt` is mixed into the agent's
 * hardware-id hash, so both must stay stable for the life of the install.
 * Left unconfigured, they are derived from APP_KEY.
 */
final class AgentServer
{
    public static function id(): string
    {
        $configured = config('signature.devices.agent.server_id');

        if (is_string($configured) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $configured)) {
            return $configured;
        }

        return substr(hash_hmac('sha256', 'kukux-agent-server-id', (string) config('app.key')), 0, 24);
    }

    public static function salt(): string
    {
        $configured = config('signature.devices.agent.salt');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'kukux-agent-salt', (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    public static function name(): string
    {
        return (string) config('app.name', 'Laravel');
    }

    /**
     * The origin the agent is talking to. The agent aborts pairing when this
     * differs from the origin it called, so it is the request's own — behind
     * a proxy, that needs TrustProxies configured like any HTTPS-aware app.
     */
    public static function origin(): string
    {
        return request()->getSchemeAndHttpHost();
    }

    public static function scheme(): string
    {
        return (string) config('signature.devices.agent.scheme', 'kukuxsign');
    }

    public static function enabled(): bool
    {
        return (bool) config('signature.devices.enabled', true)
            && (bool) config('signature.devices.agent.enabled', false);
    }

    /** 32 random bytes, base64url without padding (43 characters). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function versionAtLeast(string $version, string $minimum): bool
    {
        $parts = fn (string $v): array => array_map(
            fn (string $n): int => (int) $n,
            array_pad(explode('.', preg_replace('/[^0-9.].*$/', '', $v)), 3, '0'),
        );

        [$a, $b] = [$parts($version), $parts($minimum)];

        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] > $b[$i];
            }
        }

        return true;
    }
}
