<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * A slot was asked to sign while its signatory is unassigned, unregistered, or has declined.
 */
class SignatoryNotReadyException extends \RuntimeException
{
}
