<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub\Api;

use Kukux\DigitalSignature\Contracts\DigestSigner;

/** Records what it was asked to sign; returns recognisable "CMS" bytes. */
class FakeDigestSigner implements DigestSigner
{
    /** @var array<int, array{digest: string, certificate: string, chain: array<int, string>, tsa: ?string}> */
    public array $calls = [];

    public ?\Throwable $failWith = null;

    public function signDigest(
        string $digestHex,
        string $certificatePem,
        mixed $privateKey,
        array $chainPem = [],
        ?string $tsaUrl = null,
    ): string {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->calls[] = ['digest' => $digestHex, 'certificate' => $certificatePem, 'chain' => $chainPem, 'tsa' => $tsaUrl];

        return "\x30\x80FAKE-CMS:".$digestHex;
    }
}
