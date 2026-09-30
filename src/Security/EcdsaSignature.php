<?php

namespace Kukux\DigitalSignature\Security;

use InvalidArgumentException;

/**
 * Converts ECDSA signatures between the two encodings in play.
 *
 * Web Crypto returns P-256 signatures as raw `r‖s` (IEEE P1363, 64 bytes).
 * OpenSSL — and so `openssl_verify` — expects an ASN.1 DER
 * `SEQUENCE { INTEGER r, INTEGER s }`. Feeding the raw form to OpenSSL fails
 * every verification, silently looking like a bad key.
 */
final class EcdsaSignature
{
    public static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new InvalidArgumentException('A raw P-256 signature is exactly 64 bytes.');
        }

        $body = self::derInteger(substr($raw, 0, 32)).self::derInteger(substr($raw, 32, 32));

        return "\x30".self::derLength(strlen($body)).$body;
    }

    public static function derToRaw(string $der): string
    {
        $offset = 0;

        if (($der[$offset++] ?? '') !== "\x30") {
            throw new InvalidArgumentException('Not a DER SEQUENCE.');
        }

        self::readLength($der, $offset);

        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new InvalidArgumentException('Expected a DER INTEGER.');
            }

            $len = self::readLength($der, $offset);
            $int = ltrim(substr($der, $offset, $len), "\x00");
            $offset += $len;

            if (strlen($int) > 32) {
                throw new InvalidArgumentException('Integer too large for P-256.');
            }

            $raw .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        // A set high bit would read as negative; DER needs a leading zero.
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derLength(int $length): string
    {
        return $length < 0x80 ? chr($length) : "\x81".chr($length);
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = ord($der[$offset++] ?? "\x00");

        if ($first < 0x80) {
            return $first;
        }

        $count = $first & 0x7F;
        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset++] ?? "\x00");
        }

        return $length;
    }
}
