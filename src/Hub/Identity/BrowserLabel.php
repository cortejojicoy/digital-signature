<?php

namespace Kukux\DigitalSignature\Hub\Identity;

/**
 * "Chrome on macOS" from a User-Agent: what the agent shows beside the match
 * code, so a person can tell their own browser from a phishing page's (R3).
 * Coarse on purpose; it's a hint for a human, not a fingerprint.
 */
final class BrowserLabel
{
    public static function from(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/')                                => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')  => 'Opera',
            str_contains($ua, 'Firefox/')                            => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/')                             => 'Safari',
            default                                                  => 'A browser',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android')                             => 'Android',
            str_contains($ua, 'CrOS')                                => 'ChromeOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Windows')                             => 'Windows',
            str_contains($ua, 'Linux')                               => 'Linux',
            default                                                  => null,
        };

        return $os === null ? $browser : "{$browser} on {$os}";
    }
}
