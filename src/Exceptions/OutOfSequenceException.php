<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * A signatory tried to sign ahead of an earlier required slot in a sequential session.
 */
class OutOfSequenceException extends \RuntimeException
{
}
