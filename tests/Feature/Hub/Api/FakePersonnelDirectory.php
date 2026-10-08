<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub\Api;

use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\Personnel;

/** An in-memory registry for the hub API tests. */
class FakePersonnelDirectory implements PersonnelDirectory
{
    /** @var array<string, Personnel> */
    public array $people = [];

    public function add(Personnel $person): Personnel
    {
        return $this->people[$person->key] = $person;
    }

    public function search(string $query, int $limit = 10): array
    {
        return array_slice(array_values(array_filter(
            $this->people,
            fn (Personnel $p) => $p->active && (
                ($p->empNo !== null && strcasecmp($p->empNo, $query) === 0)
                || str_contains(mb_strtolower($p->name), mb_strtolower($query))
            ),
        )), 0, $limit);
    }

    public function find(string $key): ?Personnel
    {
        return $this->people[$key] ?? null;
    }

    public function isActive(string $key): bool
    {
        return (bool) $this->find($key)?->active;
    }

    public function toClaims(Personnel $person): array
    {
        return [
            'sub'      => $person->key,
            'name'     => $person->name,
            'email'    => $person->email,
            'emp_no'   => $person->empNo,
            'unit'     => $person->unit,
            'position' => $person->position,
        ];
    }
}
