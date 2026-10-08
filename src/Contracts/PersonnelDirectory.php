<?php

namespace Kukux\DigitalSignature\Contracts;

use Kukux\DigitalSignature\Hub\Personnel;

/**
 * Hub mode: who works at the university. The hub app feeds it from the HR
 * Kafka topics; the package only reads it, through this contract.
 *
 * The default, Hub\Identity\EloquentPersonnelDirectory, reads a model named in
 * config('signature.hub.personnel'). Bind your own to read anything else.
 */
interface PersonnelDirectory
{
    /**
     * Active personnel matching a name fragment or an exact employee number.
     * Implementations must not return inactive people and must cap results.
     *
     * @return array<int, Personnel>
     */
    public function search(string $query, int $limit = 10): array;

    public function find(string $key): ?Personnel;

    public function isActive(string $key): bool;

    /**
     * The claims an app may see about this person (userinfo / people API):
     * at least sub, name, email, emp_no.
     *
     * @return array<string, mixed>
     */
    public function toClaims(Personnel $person): array;
}
