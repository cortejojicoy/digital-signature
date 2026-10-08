<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

/**
 * The hub could not be reached: a connection error, a 5xx, or the circuit
 * breaker is open after repeated failures (risk R1). Nothing was decided, so
 * whatever was being attempted can be retried later.
 */
class HubUnavailableException extends HubException
{
}
