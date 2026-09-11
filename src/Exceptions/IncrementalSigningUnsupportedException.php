<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * The configured PDF signer driver cannot append a signature without rebuilding the document.
 */
class IncrementalSigningUnsupportedException extends \RuntimeException
{
}
