<?php

namespace Kukux\DigitalSignature\Tests\Support;

/** An app service a guard depends on, to prove guards get constructor injection. */
class LeaveLedger
{
    /** @param  array<int, int>  $days  person id => days on file */
    public function __construct(public array $days = [])
    {
    }

    public function daysFor(int $personId): int
    {
        return $this->days[$personId] ?? 0;
    }
}
