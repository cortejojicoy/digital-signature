<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Thrown when a signature image's embedded metadata indicates it was created
 * on a different machine (user-agent / IP combination) than the one now
 * attempting to use it.
 *
 * Only raised when signature.metadata.enforce_machine_lock = true.
 */
class MachineBindingException extends \RuntimeException {}
