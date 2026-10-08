<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use RuntimeException;

/** An identity step that can't happen; the message is safe to show the person. */
class IdentityException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
