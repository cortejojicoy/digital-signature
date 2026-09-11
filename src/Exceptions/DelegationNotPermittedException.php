<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * A signature delegation was created or used outside the grantor's own authenticated session.
 */
class DelegationNotPermittedException extends \RuntimeException
{
}
