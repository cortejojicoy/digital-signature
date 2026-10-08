<?php

namespace Kukux\DigitalSignature\Support;

use InvalidArgumentException;

/**
 * Which parts of the package are switched on: config('signature.mode').
 *
 *   standalone  today's behaviour (default)
 *   hub         signature.uplb.edu.ph
 *   client      an app that uses the hub
 *
 * See docs/hub/index.md.
 */
final class SignatureMode
{
    public const STANDALONE = 'standalone';

    public const HUB = 'hub';

    public const CLIENT = 'client';

    public static function current(): string
    {
        $mode = (string) (config('signature.mode') ?: self::STANDALONE);

        if (! in_array($mode, [self::STANDALONE, self::HUB, self::CLIENT], true)) {
            throw new InvalidArgumentException(sprintf(
                'SIGNATURE_MODE must be standalone, hub or client; got [%s].',
                $mode,
            ));
        }

        return $mode;
    }

    public static function isStandalone(): bool
    {
        return self::current() === self::STANDALONE;
    }

    public static function isHub(): bool
    {
        return self::current() === self::HUB;
    }

    public static function isClient(): bool
    {
        return self::current() === self::CLIENT;
    }

    /**
     * Client mode can't do anything without these. Checked at boot so a
     * half-configured app fails on deploy, not on someone's first signature.
     *
     * @return array<int, string> the missing env keys
     */
    public static function missingClientConfig(): array
    {
        $missing = [];

        foreach ([
            'url'            => 'SIGNATURE_HUB_URL',
            'client_id'      => 'SIGNATURE_HUB_CLIENT_ID',
            'client_secret'  => 'SIGNATURE_HUB_CLIENT_SECRET',
            'webhook_secret' => 'SIGNATURE_HUB_WEBHOOK_SECRET',
        ] as $key => $env) {
            if (blank(config("signature.hub.{$key}"))) {
                $missing[] = $env;
            }
        }

        return $missing;
    }
}
