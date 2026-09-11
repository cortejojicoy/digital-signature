<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

/**
 * Finds the person who fills a given slot on a given record.
 *
 * Implementations are pure lookups — no side effects, no authorization.
 * Returning null means "nobody is tagged for this role yet", which the
 * router surfaces as the `unassigned` state rather than an error.
 */
interface SignatoryResolver
{
    /**
     * @return Authenticatable|Model|null The tagged signatory, or null when unassigned.
     */
    public function resolve(Model $record, SlotDefinition $slot): Authenticatable|Model|null;
}
