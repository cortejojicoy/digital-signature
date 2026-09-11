<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SignatureDelegation;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * A signature was applied on its owner's behalf under a standing delegation,
 * without them being present in the request.
 *
 * The package always notifies the signatory when this fires — silent signing
 * is not acceptable even with consent. Do not suppress that listener.
 */
class SignatureAutoAffixed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SignatureRequest $request,
        public readonly SignatureDelegation $delegation,
    ) {}
}
