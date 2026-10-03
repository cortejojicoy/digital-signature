<?php

namespace Kukux\DigitalSignature\Contracts;

use Kukux\DigitalSignature\Routing\RoutingResult;

/**
 * A precondition checked before the record exists: "is there anything to
 * route at all?" Runs outside the transaction, so a refusal costs nothing.
 *
 * Gets what the caller has in hand (a subject, say a Personnel row, plus
 * context such as a period), not a record. Resolved from the container.
 */
interface PreflightGuard
{
    /**
     * @param  array<string, mixed>  $context
     * @return RoutingResult|null  null to pass; a refused result to stop
     */
    public function check(mixed $subject, array $context): ?RoutingResult;
}
