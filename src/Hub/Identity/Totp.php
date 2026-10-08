<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, 30-second steps, six
 * digits), the kind every authenticator app reads from a base32 secret. Only
 * the break-glass sign-in uses it, so it stays this small.
 */
final class Totp
{
    public const STEP = 30;

    public const DIGITS = 6;

    /**
     * The counter step the code matches within ±$window steps of now, or
     * null. Callers refuse a step already used (replay).
     */
    public static function verify(string $base32Secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', (string) $code)) {
            return null;
        }

        $secret = self::base32Decode($base32Secret);
        $counter = intdiv($time ?? time(), self::STEP);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::at($secret, $counter + $offset), $code)) {
                return $counter + $offset;
            }
        }

        return null;
    }

    /** The code for a raw (decoded) secret at a counter step. */
    public static function at(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The current code for a base32 secret (tests, setup checks). */
    public static function now(string $base32Secret, ?int $time = null): string
    {
        return self::at(self::base32Decode($base32Secret), intdiv($time ?? time(), self::STEP));
    }

    public static function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim(preg_replace('/[\s-]/', '', $input), '='));

        if ($input === '' || strspn($input, $alphabet) !== strlen($input)) {
            throw new InvalidArgumentException('The TOTP secret must be base32.');
        }

        $bits = '';
        foreach (str_split($input) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
