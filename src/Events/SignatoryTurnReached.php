<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * A signatory can sign now: in a parallel session as soon as they're routed,
 * in a sequential one once everyone before them has signed. Fires once per
 * request. The package notifies the signatory on it
 * (see SendSignatureRequestedNotification).
 *
 * Unlike SignatureRequested, which fires for every routed slot the moment a
 * session opens, this one waits for the signatory's turn, so nobody is told
 * "ready for your signature" while they still can't sign.
 */
class SignatoryTurnReached implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SignatureRequest $request) {}
}
