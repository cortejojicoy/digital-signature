<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Kukux\DigitalSignature\Contracts\PreflightGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;

class HasLeaveDays implements PreflightGuard
{
    public function __construct(private LeaveLedger $ledger)
    {
    }

    public function check(mixed $person, array $context): ?RoutingResult
    {
        return $this->ledger->daysFor((int) $person->getKey()) === 0
            ? RoutingResult::refused(
                'no_leave_days',
                ['period' => (string) ($context['period'] ?? '')],
                title: 'Nothing to file',
                body: 'No leave days for :period.',
            )
            : null;
    }
}
