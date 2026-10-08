<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use RuntimeException;

/**
 * Client mode: a person's signature is created, changed and revoked only at
 * the hub. Thrown by SignatureManager::store() so nothing can register a
 * signature in this app directly.
 */
class SignatureManagedAtHubException extends RuntimeException
{
}
