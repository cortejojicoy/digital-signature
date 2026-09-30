<?php

namespace Kukux\DigitalSignature\Events;

use Kukux\DigitalSignature\Models\SigningDevice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceRegistered
{
    use Dispatchable, SerializesModels;
    public function __construct(public readonly SigningDevice $device) {}
}
