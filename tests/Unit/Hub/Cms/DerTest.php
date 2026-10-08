<?php

use Kukux\DigitalSignature\Support\Der\Der;
use Kukux\DigitalSignature\Support\Der\DerException;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Support\Der\Oid;
use Kukux\DigitalSignature\Support\Der\X509;
use Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys;

/**
 * The DER encoder and reader under the CMS code. Expected bytes come from
 * X.690's own examples and from what OpenSSL emits for the same values.
 */
describe('DER encoding', function () {

    it('encodes lengths in short and long form', function () {
        expect(bin2hex(Der::length(0)))->toBe('00')
            ->and(bin2hex(Der::length(127)))->toBe('7f')
            ->and(bin2hex(Der::length(128)))->toBe('8180')
            ->and(bin2hex(Der::length(256)))->toBe('820100')
            ->and(bin2hex(Der::length(70000)))->toBe('83011170');
    });

    it('encodes integers minimally, keeping them positive', function () {
        expect(bin2hex(Der::integer(0)))->toBe('020100')
            ->and(bin2hex(Der::integer(1)))->toBe('020101')
            ->and(bin2hex(Der::integer(127)))->toBe('02017f')
            ->and(bin2hex(Der::integer(128)))->toBe('02020080')
            ->and(bin2hex(Der::integer(256)))->toBe('02020100')
            ->and(bin2hex(Der::integer(-1)))->toBe('0201ff')
            ->and(bin2hex(Der::integer(-129)))->toBe('0202ff7f');
    });

    it('encodes integers from a big-endian magnitude', function () {
        expect(bin2hex(Der::integerFromBinary("\x00\x00\x05")))->toBe('020105')
            ->and(bin2hex(Der::integerFromBinary("\xff\x01")))->toBe('020300ff01')
            ->and(bin2hex(Der::integerFromBinary('')))->toBe('020100');
    });

    it('encodes object identifiers', function () {
        expect(bin2hex(Der::oid(Oid::SIGNED_DATA)))->toBe('06092a864886f70d010702')
            ->and(bin2hex(Der::oid(Oid::SHA256)))->toBe('0609608648016503040201')
            ->and(bin2hex(Der::oid(Oid::ECDSA_WITH_SHA256)))->toBe('06082a8648ce3d040302')
            ->and(bin2hex(Der::oid('2.999.3')))->toBe('0603883703');
    });

    it('rejects malformed object identifiers', function () {
        expect(fn () => Der::oid('1.2.x'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Der::oid('1.40'))->toThrow(InvalidArgumentException::class);
    });

    it('encodes the simple types', function () {
        expect(bin2hex(Der::null()))->toBe('0500')
            ->and(bin2hex(Der::boolean(true)))->toBe('0101ff')
            ->and(bin2hex(Der::boolean(false)))->toBe('010100')
            ->and(bin2hex(Der::octetString("\x01\x02")))->toBe('04020102')
            ->and(bin2hex(Der::sequence(Der::integer(1), Der::null())))->toBe('3005020101' . '0500');
    });

    it('writes times in UTC, switching to GeneralizedTime from 2050', function () {
        $manila = new DateTimeImmutable('2026-10-08 08:30:05', new DateTimeZone('Asia/Manila'));

        expect(Der::utcTime($manila))->toBe("\x17\x0d".'261008003005Z')
            ->and(Der::generalizedTime($manila))->toBe("\x18\x0f".'20261008003005Z')
            ->and(Der::time($manila)[0])->toBe("\x17")
            ->and(Der::time(new DateTimeImmutable('2050-01-01 00:00:00 UTC'))[0])->toBe("\x18")
            ->and(fn () => Der::utcTime(new DateTimeImmutable('2050-01-01 UTC')))->toThrow(InvalidArgumentException::class);
    });

    it('sorts SET OF members by their encodings', function () {
        $long  = Der::octetString("\x01\x02");
        $short = Der::octetString("\x01");
        $int   = Der::integer(5);

        // 02.. < 04 01 01 < 04 02 01 02
        expect(Der::set($long, $short, $int))->toBe("\x31".Der::length(strlen($int.$short.$long)).$int.$short.$long)
            ->and(Der::setUnsorted($long, $short))->toBe("\x31\x07".$long.$short);
    });

    it('tags explicitly and implicitly, keeping the constructed bit', function () {
        $set = Der::set(Der::integer(1));

        expect(bin2hex(Der::explicit(0, Der::integer(1))))->toBe('a003020101')
            ->and(bin2hex(Der::implicit(0, $set)))->toBe('a003020101')
            ->and(bin2hex(Der::implicit(1, Der::octetString('A'))))->toBe('810141')
            ->and(Der::raw("\x05\x00"))->toBe("\x05\x00");
    });
});

describe('DER reading', function () {

    it('reads back what it writes', function () {
        $der = Der::sequence(
            Der::oid(Oid::MESSAGE_DIGEST),
            Der::explicit(0, Der::integer(-129)),
            Der::octetString(str_repeat('x', 300)),
            Der::integerFromBinary("\xff\xee"),
        );

        $root = Element::parse($der);

        expect($root->constructed)->toBeTrue()
            ->and($root->children())->toHaveCount(4)
            ->and($root->child(0)->oid())->toBe(Oid::MESSAGE_DIGEST)
            ->and($root->child(1)->isContext(0))->toBeTrue()
            ->and($root->contextChild(0)->child(0)->int())->toBe(-129)
            ->and(strlen($root->child(2)->content))->toBe(300)
            ->and($root->child(3)->integerBytes())->toBe("\xff\xee")
            ->and($root->encoded)->toBe($der);
    });

    it('refuses trailing data, truncation and indefinite lengths', function () {
        expect(fn () => Element::parse(Der::null()."\x00"))->toThrow(DerException::class)
            ->and(fn () => Element::parse("\x04\x05abc"))->toThrow(DerException::class)
            ->and(fn () => Element::parse("\x30\x80\x00\x00"))->toThrow(DerException::class)
            ->and(fn () => Element::parse(Der::null())->child(0))->toThrow(DerException::class);
    });

    it('pulls the issuer and serial out of a certificate verbatim', function () {
        $pem    = CmsTestKeys::rsa()['cert'];
        $cert   = X509::fromPem($pem);
        $parsed = openssl_x509_parse($pem);

        // The serial, as openssl reports it.
        expect(strtoupper(bin2hex(Element::parse($cert->serial)->integerBytes())))
            ->toBe(strtoupper(ltrim($parsed['serialNumberHex'], '0')));

        // The issuer is the CA's subject, byte-for-byte.
        $ca = X509::fromPem(CmsTestKeys::rsa()['chain'][0]);
        $caTbs = Element::parse($ca->der)->child(0);
        $caSubject = $caTbs->child($caTbs->child(0)->isContext(0) ? 5 : 4)->encoded;

        expect($cert->issuer)->toBe($caSubject)
            ->and(X509::toPem($cert->der))->toBe(str_replace("\r", '', $pem));
    });

    it('rejects something that is not a certificate', function () {
        expect(fn () => X509::fromDer(Der::sequence(Der::integer(1))))->toThrow(InvalidArgumentException::class)
            ->and(fn () => X509::fromPem('nope'))->toThrow(InvalidArgumentException::class);
    });
});
