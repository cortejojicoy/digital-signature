<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The hub answered with an error, or could not be asked at all.
 *
 * Every hub error body is `{"error": "<code>", "message": "<text>"}`
 * (docs/hub/contracts.md); the code is kept so callers can branch on it
 * without parsing the message.
 */
class HubException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, (int) $status, $previous);
    }
}
