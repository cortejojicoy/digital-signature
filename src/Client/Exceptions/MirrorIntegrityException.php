<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use RuntimeException;

/**
 * A signature image whose bytes don't hash to what the hub says they should:
 * on download, or when the stored mirror is read back before stamping (R5).
 * The image is never written or stamped.
 */
class MirrorIntegrityException extends RuntimeException
{
}
