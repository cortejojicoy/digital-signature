<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * An action was attempted on a session that is complete, cancelled, or expired.
 */
class SigningSessionClosedException extends \RuntimeException
{
}
