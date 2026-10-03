<?php

namespace Kukux\DigitalSignature\Signatories;

/**
 * One person in one role, as a SignatoryDefaults implementation returns it:
 * their key in the signatory model's table, and the position printed under
 * their name on the document.
 */
final readonly class SignatoryAssignment
{
    public function __construct(
        public int|string $id,
        public ?string $position = null,
    ) {
    }
}
