<?php

namespace Kukux\DigitalSignature\Support;

/**
 * A file did not have the SHA-256 it was supposed to (plan R5): a stale or
 * edited mirror, or an object changed inside the bucket. Callers treat it as
 * a tamper signal — re-pull from the hub and alert — never as a retryable
 * read error.
 */
class LocalCopyIntegrityException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly string $expectedSha256,
        public readonly ?string $actualSha256,
    ) {
        parent::__construct($actualSha256 === null
            ? "'{$path}' is missing, so its SHA-256 could not be checked."
            : "'{$path}' does not match its expected SHA-256 (expected {$expectedSha256}, got {$actualSha256}).");
    }
}
