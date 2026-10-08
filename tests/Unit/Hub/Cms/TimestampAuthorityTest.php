<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Hub\Cms\CmsException;
use Kukux\DigitalSignature\Hub\Cms\SignedDataReader;
use Kukux\DigitalSignature\Hub\Cms\TimestampAuthority;
use Kukux\DigitalSignature\Support\Der\Der;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Support\Der\Oid;
use Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys;

/**
 * RFC 3161 against a fake TSA. The fake answers like a real one in shape —
 * TimeStampResp { PKIStatusInfo, ContentInfo(SignedData(TSTInfo)) } echoing
 * the request's imprint and nonce — but its token is unsigned: what is under
 * test is our request and our checks, not a TSA's signature.
 */
const FAKE_TSA = 'https://tsa.test/tsr';

/**
 * @param  callable(string $imprint, string $nonce): array{0:string,1:string}|null  $tamper
 */
function fakeTsaResponse(string $requestDer, int $status = 0, ?callable $tamper = null): string
{
    $req     = Element::parse($requestDer);
    $imprint = $req->child(1)->child(1)->content;
    $nonce   = $req->child(2)->integerBytes();

    if ($tamper !== null) {
        [$imprint, $nonce] = $tamper($imprint, $nonce);
    }

    $statusInfo = $status === 0
        ? Der::sequence(Der::integer(0))
        : Der::sequence(Der::integer($status), Der::sequence(Der::utf8String('policy not accepted')));

    if ($status > 1) {
        return Der::sequence($statusInfo);
    }

    $tstInfo = Der::sequence(
        Der::integer(1),
        Der::oid('1.3.6.1.4.1.99999.1'),
        Der::sequence(Der::sequence(Der::oid(Oid::SHA256)), Der::octetString($imprint)),
        Der::integer(4242),
        Der::generalizedTime(new DateTimeImmutable('2026-10-08 00:00:00 UTC')),
        Der::sequence(Der::integer(1)),           // accuracy
        Der::integerFromBinary($nonce),
    );

    $token = Der::sequence(
        Der::oid(Oid::SIGNED_DATA),
        Der::explicit(0, Der::sequence(
            Der::integer(3),
            Der::set(Der::sequence(Der::oid(Oid::SHA256))),
            Der::sequence(Der::oid(Oid::TST_INFO), Der::explicit(0, Der::octetString($tstInfo))),
            Der::set(),
        )),
    );

    return Der::sequence($statusInfo, $token);
}

describe('TimestampAuthority', function () {

    it('encodes a TimeStampReq with sha256 imprint, nonce and certReq', function () {
        $hash = hash('sha256', 'signature value', true);
        $req  = Element::parse(app(TimestampAuthority::class)->buildRequest($hash, "\x12\x34\x56"));

        expect($req->child(0)->int())->toBe(1)
            ->and($req->child(1)->child(0)->child(0)->oid())->toBe(Oid::SHA256)
            ->and($req->child(1)->child(1)->content)->toBe($hash)
            ->and($req->child(2)->integerBytes())->toBe("\x12\x34\x56")
            ->and($req->child(3)->encoded)->toBe("\x01\x01\xff");

        expect(fn () => app(TimestampAuthority::class)->buildRequest('short', "\x01"))
            ->toThrow(CmsException::class);
    });

    it('posts the query and returns the granted token', function () {
        Http::fake([FAKE_TSA => fn (Request $r) => Http::response(fakeTsaResponse($r->body()), 200, ['Content-Type' => 'application/timestamp-reply'])]);

        $hash  = hash('sha256', 'x', true);
        $token = app(TimestampAuthority::class)->timestamp(FAKE_TSA, $hash);

        expect(Element::parse($token)->child(0)->oid())->toBe(Oid::SIGNED_DATA);

        Http::assertSent(fn (Request $r) => $r->url() === FAKE_TSA
            && $r->method() === 'POST'
            && $r->header('Content-Type')[0] === 'application/timestamp-query'
            && Element::parse($r->body())->child(1)->child(1)->content === $hash);
    });

    it('refuses a rejection, naming the status', function () {
        Http::fake([FAKE_TSA => fn (Request $r) => Http::response(fakeTsaResponse($r->body(), 2))]);

        expect(fn () => app(TimestampAuthority::class)->timestamp(FAKE_TSA, hash('sha256', 'x', true)))
            ->toThrow(CmsException::class, 'refused the request (rejection): policy not accepted');
    });

    it('refuses a token for another hash or another nonce', function (Closure $tamper, string $message) {
        Http::fake([FAKE_TSA => fn (Request $r) => Http::response(fakeTsaResponse($r->body(), 0, $tamper))]);

        expect(fn () => app(TimestampAuthority::class)->timestamp(FAKE_TSA, hash('sha256', 'x', true)))
            ->toThrow(CmsException::class, $message);
    })->with([
        'imprint' => [fn ($imprint, $nonce) => [hash('sha256', 'other', true), $nonce], 'different hash'],
        'nonce'   => [fn ($imprint, $nonce) => [$imprint, "\x7f\x01"], 'nonce'],
    ]);

    it('refuses HTTP errors and garbage', function () {
        Http::fake([FAKE_TSA => Http::sequence()->push('nope', 500)->push('not der', 200)]);

        $tsa = app(TimestampAuthority::class);

        expect(fn () => $tsa->timestamp(FAKE_TSA, hash('sha256', 'x', true)))->toThrow(CmsException::class, 'HTTP 500')
            ->and(fn () => $tsa->timestamp(FAKE_TSA, hash('sha256', 'x', true)))->toThrow(CmsException::class, 'malformed');
    });

    it('timestamps a CMS signature value as an unsigned attribute', function () {
        Http::fake([FAKE_TSA => fn (Request $r) => Http::response(fakeTsaResponse($r->body()))]);

        $identity = CmsTestKeys::rsa();
        $content  = 'timestamped content';
        $der      = app(DigestSigner::class)->signDigest(hash('sha256', $content), $identity['cert'], $identity['key'], $identity['chain'], FAKE_TSA);
        $cms      = SignedDataReader::parse($der);

        $token = $cms->timestampToken();
        expect($token)->not->toBeNull()
            ->and($cms->verifiesWithSignerCertificate())->toBeTrue();

        // The imprint is sha256 of the SignerInfo's signature value.
        $tstInfo = Element::parse(Element::parse($token)->child(1)->child(0)->child(2)->child(1)->child(0)->content);
        expect($tstInfo->child(2)->child(1)->content)->toBe(hash('sha256', $cms->signature, true));

        if (CmsTestKeys::openssl() !== null) {
            [$ok, $output] = CmsTestKeys::opensslVerify($der, $content);
            expect($ok)->toBeTrue($output);
        }
    });

    it('does not call a TSA when none is configured', function () {
        Http::fake();

        $identity = CmsTestKeys::ec();
        app(DigestSigner::class)->signDigest(str_repeat('1', 64), $identity['cert'], $identity['key']);

        Http::assertNothingSent();
    });
});
