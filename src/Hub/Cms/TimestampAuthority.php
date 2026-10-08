<?php

namespace Kukux\DigitalSignature\Hub\Cms;

use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Support\Der\Der;
use Kukux\DigitalSignature\Support\Der\DerException;
use Kukux\DigitalSignature\Support\Der\Element;
use Kukux\DigitalSignature\Support\Der\Oid;

/**
 * RFC 3161 client: asks a timestamp authority to countersign a hash and
 * returns its TimeStampToken.
 *
 * TCPDF's own TSA hook (`applyTSA()`) is an empty @TODO, so this is the only
 * code in the package that actually obtains a timestamp.
 *
 * The response is checked before it is embedded — status granted, the imprint
 * is the hash we sent, the nonce is ours — because a token for some other
 * hash would be carried into the PDF and only fail much later, in a reader.
 * The TSA's own signature on the token is NOT verified here; that needs the
 * TSA's trust anchor and is the PDF reader's job, as it is for the signer's
 * certificate.
 */
class TimestampAuthority
{
    /** PKIStatus values that come with a token (RFC 3161 §2.4.2). */
    private const GRANTED                = 0;
    private const GRANTED_WITH_MODS      = 1;

    private const STATUS_NAMES = [
        0 => 'granted',
        1 => 'grantedWithMods',
        2 => 'rejection',
        3 => 'waiting',
        4 => 'revocationWarning',
        5 => 'revocationNotification',
    ];

    public function __construct(
        protected int $timeout = 15,
    ) {}

    /**
     * @param  string  $sha256  The 32-byte hash to timestamp (binary).
     * @return string  DER TimeStampToken (a ContentInfo), ready to embed.
     */
    public function timestamp(string $url, string $sha256): string
    {
        $nonce   = $this->nonce();
        $request = $this->buildRequest($sha256, $nonce);

        try {
            $response = Http::withBody($request, 'application/timestamp-query')
                ->accept('application/timestamp-reply')
                ->timeout($this->timeout)
                ->post($url);
        } catch (\Throwable $e) {
            throw new CmsException("Timestamp authority {$url} could not be reached: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new CmsException("Timestamp authority {$url} answered HTTP {$response->status()}.");
        }

        return $this->tokenFrom($response->body(), $sha256, $nonce);
    }

    /**
     * TimeStampReq { version 1, messageImprint { sha256, hash }, nonce,
     * certReq TRUE } — certReq so the token carries the TSA certificate and
     * a reader can validate it without fetching anything.
     */
    public function buildRequest(string $sha256, string $nonce): string
    {
        if (strlen($sha256) !== 32) {
            throw new CmsException('A timestamp request needs a 32-byte SHA-256 hash.');
        }

        return Der::sequence(
            Der::integer(1),
            Der::sequence(Der::sequence(Der::oid(Oid::SHA256)), Der::octetString($sha256)),
            Der::integerFromBinary($nonce),
            Der::boolean(true),
        );
    }

    /**
     * Check a TimeStampResp and return its token.
     *
     * @param  string  $sha256  The hash that was sent.
     * @param  string  $nonce  The nonce that was sent (big-endian magnitude).
     */
    public function tokenFrom(string $responseDer, string $sha256, string $nonce): string
    {
        try {
            $resp   = Element::parse($responseDer)->expect(Der::SEQUENCE, 'a TimeStampResp');
            $status = $resp->child(0)->expect(Der::SEQUENCE, 'a PKIStatusInfo');
            $code   = $status->child(0)->int();

            if ($code !== self::GRANTED && $code !== self::GRANTED_WITH_MODS) {
                $name = self::STATUS_NAMES[$code] ?? "status {$code}";
                $text = $this->statusText($status);

                throw new CmsException("The timestamp authority refused the request ({$name})".($text ? ": {$text}" : '.'));
            }

            $token = $resp->children()[1] ?? null;

            if ($token === null) {
                throw new CmsException('The timestamp authority granted the request but sent no token.');
            }

            $tstInfo = $this->tstInfo($token->expect(Der::SEQUENCE, 'a TimeStampToken'));

            // TSTInfo { version, policy, messageImprint, serialNumber, genTime,
            //           accuracy?, ordering?, nonce?, tsa [0]?, extensions [1]? }
            $imprint = $tstInfo->child(2)->expect(Der::SEQUENCE, 'the messageImprint');

            if ($imprint->child(1)->content !== $sha256) {
                throw new CmsException('The timestamp token is for a different hash than the one sent.');
            }

            if ($this->tokenNonce($tstInfo) !== ltrim($nonce, "\0")) {
                throw new CmsException('The timestamp token does not carry the nonce that was sent.');
            }
        } catch (DerException $e) {
            throw new CmsException('The timestamp authority sent a malformed response: '.$e->getMessage(), 0, $e);
        }

        return $token->encoded;
    }

    // -------------------------------------------------------------------------

    /**
     * Eight random bytes; integerFromBinary() keeps the value positive
     * whatever the high bit.
     */
    protected function nonce(): string
    {
        $nonce = random_bytes(8);

        // Avoid a leading zero byte so the sent and echoed forms compare
        // without normalising.
        return $nonce[0] === "\0" ? "\x01".substr($nonce, 1) : $nonce;
    }

    private function tstInfo(Element $token): Element
    {
        if ($token->child(0)->oid() !== Oid::SIGNED_DATA) {
            throw new CmsException('The timestamp token is not a SignedData.');
        }

        $signedData = $token->child(1)->child(0)->expect(Der::SEQUENCE, 'the SignedData');
        $encap      = $signedData->child(2)->expect(Der::SEQUENCE, 'the encapContentInfo');

        if ($encap->child(0)->oid() !== Oid::TST_INFO) {
            throw new CmsException('The timestamp token does not contain a TSTInfo.');
        }

        $eContent = $encap->contextChild(0)?->child(0)->expect(Der::OCTET_STRING, 'the eContent');

        if ($eContent === null) {
            throw new CmsException('The timestamp token has no TSTInfo content.');
        }

        return Element::parse($eContent->content)->expect(Der::SEQUENCE, 'a TSTInfo');
    }

    private function tokenNonce(Element $tstInfo): ?string
    {
        // After genTime (index 4) the only universal INTEGER is the nonce.
        foreach (array_slice($tstInfo->children(), 5) as $field) {
            if ($field->is(Der::INTEGER)) {
                return $field->integerBytes();
            }
        }

        return null;
    }

    private function statusText(Element $status): string
    {
        $free = $status->children()[1] ?? null;

        if ($free === null || ! $free->is(Der::SEQUENCE)) {
            return '';
        }

        return implode(' ', array_map(fn (Element $s): string => $s->content, $free->children()));
    }
}
