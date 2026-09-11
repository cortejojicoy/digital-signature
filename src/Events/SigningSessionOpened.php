<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * A document has been frozen for signing and its requests created.
 */
class SigningSessionOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SigningSession $session) {}
}
