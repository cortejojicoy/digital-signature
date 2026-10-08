<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

/**
 * 409 `specimen_changed`: the sign request was stamped with an image the hub
 * no longer holds. The caller re-pulls the mirror and resubmits once (A7).
 */
class SpecimenChangedException extends HubException
{
    public function __construct(
        string $message,
        public readonly ?string $currentSpecimenHash = null,
    ) {
        parent::__construct($message, 'specimen_changed', 409);
    }
}
