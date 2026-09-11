<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * A signatory refused to sign. The session stays open so the host app can
 * decide whether to reassign the role or cancel outright.
 */
class SignatureDeclined
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SignatureRequest $request,
        public readonly ?string $reason = null,
    ) {}
}
