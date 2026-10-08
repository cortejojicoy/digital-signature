<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use RuntimeException;

/** SIGNATURE_MODE=client without the hub settings it can't work without. */
class ClientMisconfiguredException extends RuntimeException
{
    /** @param  array<int, string>  $missing  env keys */
    public static function missing(array $missing): self
    {
        return new self(sprintf(
            'SIGNATURE_MODE is client, but %s %s not set. Set %s in .env (docs/hub/client.md), '
            .'or set SIGNATURE_MODE=standalone.',
            implode(', ', $missing),
            count($missing) === 1 ? 'is' : 'are',
            count($missing) === 1 ? 'it' : 'them',
        ));
    }
}
