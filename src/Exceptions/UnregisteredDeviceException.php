<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Thrown when a signature is created or used without a verified, active
 * signing device and signature.devices.require = enforce — or when the
 * presented device has been revoked.
 */
class UnregisteredDeviceException extends \RuntimeException {}
