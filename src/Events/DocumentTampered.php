<?php

namespace Kukux\DigitalSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentVersion;

/**
 * A version of a routed document no longer matches the hash recorded when it
 * was produced: the file was replaced, edited or lost after signing. Raised
 * when it is served. Listen for it and alert someone; the history is only
 * worth anything if it can't be swapped quietly.
 */
class DocumentTampered
{
    use Dispatchable;

    public function __construct(public readonly DocumentVersion $version)
    {
    }
}
