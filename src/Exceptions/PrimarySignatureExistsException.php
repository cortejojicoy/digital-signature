<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Thrown when a user tries to register a second reusable (primary) signature
 * while still owning an active one. A "primary" signature is one with no
 * associated Signable (signable_id IS NULL) and a status other than 'revoked'.
 *
 * Each user is limited to a single active primary signature so that document
 * signing flows can unambiguously default to it. Revoking the existing primary
 * lifts the restriction.
 */
class PrimarySignatureExistsException extends \RuntimeException {}
