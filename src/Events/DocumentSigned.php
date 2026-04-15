<?php

namespace Kukux\DigitalSignature\Events;

use Kukux\DigitalSignature\Models\Signature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentSigned
{
    use Dispatchable, SerializesModels;
    public function __construct(public readonly Signature $signature) {}
}
