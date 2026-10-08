<?php

namespace Kukux\DigitalSignature\Support\Der;

use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A minimal DER encoder: just the ASN.1 types a detached CMS SignedData and an
 * RFC 3161 TimeStampReq are made of.
 *
 * Every method returns a complete encoding (tag, length, content), so the
 * results nest by plain string concatenation, and anything already encoded
 * (a certificate, a timestamp token, an issuer Name lifted out of a cert) is
 * passed through as-is.
 *
 * DER rather than BER matters in exactly one place here: the signed
 * attributes. A verifier re-encodes them before checking the signature, so
 * the bytes we sign have to be the canonical encoding or the signature fails
 * — hence set() sorts its members.
 */
final class Der
{
    public const SEQUENCE          = 0x30;
    public const SET               = 0x31;
    public const BOOLEAN           = 0x01;
    public const INTEGER           = 0x02;
    public const BIT_STRING        = 0x03;
    public const OCTET_STRING      = 0x04;
    public const NULL              = 0x05;
    public const OID               = 0x06;
    public const UTF8_STRING       = 0x0C;
    public const UTC_TIME          = 0x17;
    public const GENERALIZED_TIME  = 0x18;

    /** Context-specific class bits. */
    public const CONTEXT           = 0x80;

    /** Constructed bit. */
    public const CONSTRUCTED       = 0x20;

    /**
     * One TLV with a single-byte tag (every tag this package writes is < 31).
     */
    public static function tlv(int $tag, string $content): string
    {
        if ($tag < 0 || $tag > 0xFF || ($tag & 0x1F) === 0x1F) {
            throw new InvalidArgumentException("Unsupported DER tag 0x".dechex($tag).'.');
        }

        return chr($tag).self::length(strlen($content)).$content;
    }

    /**
     * Definite-length octets: short form below 128, long form above.
     */
    public static function length(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('A DER length cannot be negative.');
        }

        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('J', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    public static function sequence(string ...$encoded): string
    {
        return self::tlv(self::SEQUENCE, implode('', $encoded));
    }

    /**
     * A SET OF, members in DER order (X.690 §11.6: ascending by their
     * encodings, the shorter one first when one is a prefix of the other —
     * which is exactly what a byte-wise strcmp() gives).
     *
     * Also right for a plain SET whose members carry distinct tags, since the
     * tag is the first byte of each encoding.
     */
    public static function set(string ...$encoded): string
    {
        usort($encoded, strcmp(...));

        return self::tlv(self::SET, implode('', $encoded));
    }

    /**
     * A SET in exactly the order given — for re-emitting a set that is
     * already in a known order. Not DER when the members are unsorted.
     */
    public static function setUnsorted(string ...$encoded): string
    {
        return self::tlv(self::SET, implode('', $encoded));
    }

    public static function integer(int $value): string
    {
        if ($value === 0) {
            return self::tlv(self::INTEGER, "\0");
        }

        if ($value < 0) {
            // Two's complement, minimal: drop leading 0xFF bytes while the
            // next byte still carries the sign.
            $bytes = pack('J', $value);
            while (strlen($bytes) > 1 && $bytes[0] === "\xFF" && (ord($bytes[1]) & 0x80)) {
                $bytes = substr($bytes, 1);
            }

            return self::tlv(self::INTEGER, $bytes);
        }

        return self::integerFromBinary(pack('J', $value));
    }

    /**
     * A NON-NEGATIVE integer from its big-endian magnitude (a serial number,
     * a random nonce). Redundant leading zeros are dropped and one is added
     * back when the high bit is set, so the value is not read as negative.
     */
    public static function integerFromBinary(string $bigEndian): string
    {
        $bytes = ltrim($bigEndian, "\0");

        if ($bytes === '') {
            $bytes = "\0";
        } elseif (ord($bytes[0]) & 0x80) {
            $bytes = "\0".$bytes;
        }

        return self::tlv(self::INTEGER, $bytes);
    }

    public static function boolean(bool $value): string
    {
        return self::tlv(self::BOOLEAN, $value ? "\xFF" : "\0");
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function octetString(string $bytes): string
    {
        return self::tlv(self::OCTET_STRING, $bytes);
    }

    public static function utf8String(string $text): string
    {
        return self::tlv(self::UTF8_STRING, $text);
    }

    /**
     * An OBJECT IDENTIFIER from dotted form ("1.2.840.113549.1.7.2").
     */
    public static function oid(string $dotted): string
    {
        if (preg_match('/^[0-2](\.\d+)+$/', $dotted) !== 1) {
            throw new InvalidArgumentException("'{$dotted}' is not a dotted object identifier.");
        }

        $arcs  = array_map('intval', explode('.', $dotted));
        $first = array_shift($arcs);
        $second = array_shift($arcs);

        if ($first < 2 && $second > 39) {
            throw new InvalidArgumentException("'{$dotted}' is not a valid object identifier.");
        }

        $body = self::base128(40 * $first + $second);

        foreach ($arcs as $arc) {
            $body .= self::base128($arc);
        }

        return self::tlv(self::OID, $body);
    }

    /**
     * UTCTime, always in UTC with seconds ("YYMMDDHHMMSSZ"), as RFC 5280 and
     * RFC 5652 require. Only valid for 1950–2049.
     */
    public static function utcTime(DateTimeInterface $time): string
    {
        $utc  = self::inUtc($time);
        $year = (int) $utc->format('Y');

        if ($year < 1950 || $year > 2049) {
            throw new InvalidArgumentException("UTCTime cannot represent the year {$year}; use generalizedTime().");
        }

        return self::tlv(self::UTC_TIME, $utc->format('ymdHis').'Z');
    }

    /**
     * GeneralizedTime in UTC without fractional seconds ("YYYYMMDDHHMMSSZ").
     */
    public static function generalizedTime(DateTimeInterface $time): string
    {
        return self::tlv(self::GENERALIZED_TIME, self::inUtc($time)->format('YmdHis').'Z');
    }

    /**
     * RFC 5652's rule for signingTime (and RFC 5280's for validity): UTCTime
     * through 2049, GeneralizedTime from 2050.
     */
    public static function time(DateTimeInterface $time): string
    {
        $year = (int) self::inUtc($time)->format('Y');

        return $year >= 1950 && $year <= 2049
            ? self::utcTime($time)
            : self::generalizedTime($time);
    }

    /**
     * [n] EXPLICIT: wraps a complete encoding in a constructed context tag.
     */
    public static function explicit(int $tagNumber, string $encoded): string
    {
        self::guardTagNumber($tagNumber);

        return self::tlv(self::CONTEXT | self::CONSTRUCTED | $tagNumber, $encoded);
    }

    /**
     * [n] IMPLICIT: replaces the tag of a complete encoding, keeping its
     * length and content and its constructed bit (so an implicitly tagged
     * SET stays constructed — `certificates [0] IMPLICIT`, `signedAttrs`).
     */
    public static function implicit(int $tagNumber, string $encoded): string
    {
        self::guardTagNumber($tagNumber);

        if ($encoded === '') {
            throw new InvalidArgumentException('Nothing to tag.');
        }

        $constructed = ord($encoded[0]) & self::CONSTRUCTED;

        return chr(self::CONTEXT | $constructed | $tagNumber).substr($encoded, 1);
    }

    /**
     * An already-encoded element, unchanged. Exists so call sites that splice
     * in foreign DER (a certificate, a timestamp token) say so.
     */
    public static function raw(string $encoded): string
    {
        return $encoded;
    }

    // -------------------------------------------------------------------------

    private static function base128(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Object identifier arcs cannot be negative.');
        }

        $out = chr($value & 0x7F);
        $value >>= 7;

        while ($value > 0) {
            $out    = chr(0x80 | ($value & 0x7F)).$out;
            $value >>= 7;
        }

        return $out;
    }

    private static function guardTagNumber(int $tagNumber): void
    {
        if ($tagNumber < 0 || $tagNumber > 30) {
            throw new InvalidArgumentException("Context tag [{$tagNumber}] is out of range.");
        }
    }

    private static function inUtc(DateTimeInterface $time): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }
}
