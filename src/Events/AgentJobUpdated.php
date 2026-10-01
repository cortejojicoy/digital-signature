<?php

namespace Kukux\DigitalSignature\Events;

use Kukux\DigitalSignature\Models\AgentJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signing job changed state (claimed, completed, rejected, expired). The
 * package's own page polls; hosts running Reverb can broadcast this instead.
 */
class AgentJobUpdated
{
    use Dispatchable, SerializesModels;
    public function __construct(public readonly AgentJob $job) {}
}
