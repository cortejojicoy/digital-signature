<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Every required slot has been signed. The session's current_document_path
 * now points at the finished PDF.
 */
class SigningSessionCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SigningSession $session) {}
}
