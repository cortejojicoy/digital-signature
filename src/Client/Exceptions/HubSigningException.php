<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use Kukux\DigitalSignature\Exceptions\SignatoryNotReadyException;
use Throwable;

/**
 * Signing through the hub didn't happen, for a reason the signer can read.
 *
 * A SignatoryNotReadyException on purpose: every signing surface (the drawer,
 * the inbox, the template signer) already shows that exception's message as
 * is, so the hub's reasons reach the person without each surface learning a
 * new exception.
 *
 *   unavailable   the hub can't be reached; the placement is kept (R1)
 *   refused       the hub said no (not verified, revoked, separated…)
 *   unlinked      this account was never signed in through the hub
 *   no_mirror     no signature image from the hub yet
 */
class HubSigningException extends SignatoryNotReadyException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        return new self('Signing is unavailable. Your placement is saved.', 'unavailable', $previous);
    }
}
