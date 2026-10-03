<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryDefaults;
use Kukux\DigitalSignature\Signatories\SignatoryAssignment;

/** The reviewer is the applicant's supervisor. */
class SupervisorDefaults implements SignatoryDefaults
{
    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        $supervisor = $record->person?->supervisor;

        return $supervisor === null ? null : new SignatoryAssignment($supervisor->getKey(), 'Supervisor');
    }
}
