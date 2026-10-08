<?php

namespace Kukux\DigitalSignature\Client\Exceptions;

/**
 * The hub understood and said no: 422 (`not_verified`, `no_signature`,
 * `certificate_revoked`, `separated`, `unknown_person`) or 403 (`not_linked`).
 */
class HubRefusedException extends HubException
{
}
