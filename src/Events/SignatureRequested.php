<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * A slot has been routed to a person and is now waiting on them.
 * Listen to this to notify the signatory.
 */
class SignatureRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SignatureRequest $request) {}
}
