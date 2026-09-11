<?php

namespace Kukux\DigitalSignature\Signatories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kukux\DigitalSignature\Contracts\SignatoryResolver;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

/**
 * Resolves a signatory from a string binding on the host record.
 *
 * Two accepted forms, tried in this order:
 *
 *   'attestedBy'      → an Eloquent relation or accessor returning a user
 *   'attested_by_id'  → a foreign key; the configured auth user model is
 *                       loaded by primary key
 *
 * The relation form is preferred because it lets the host control eager
 * loading and scoping. The key form exists so a record that stores only an
 * id — with no relation defined — still works without writing a closure.
 */
class RelationResolver implements SignatoryResolver
{
    public function __construct(protected string $binding)
    {
    }

    public function resolve(Model $record, SlotDefinition $slot): Authenticatable|Model|null
    {
        $resolved = $this->fromRelation($record);

        if ($resolved !== null) {
            return $resolved;
        }

        return $this->fromForeignKey($record);
    }

    /**
     * Try the binding as a relation or accessor. Guarded with a broad catch:
     * a host model may define `attestedBy()` as something that throws when
     * a parent is missing, and a missing signatory must degrade to "unassigned"
     * rather than break the whole routing pass.
     */
    protected function fromRelation(Model $record): Model|null
    {
        try {
            $value = $record->{$this->binding};
        } catch (\Throwable) {
            // Unknown property, a relation whose parent is missing, an
            // accessor that threw — all mean "no signatory", not "crash".
            return null;
        }

        if ($value instanceof Model) {
            return $value;
        }

        if ($value instanceof Relation) {
            $first = $value->first();

            return $first instanceof Model ? $first : null;
        }

        return null;
    }

    /**
     * Try the binding as a foreign key column on the record.
     */
    protected function fromForeignKey(Model $record): Model|null
    {
        if (! array_key_exists($this->binding, $record->getAttributes())) {
            return null;
        }

        $id = $record->getAttribute($this->binding);

        if ($id === null || $id === '') {
            return null;
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return null;
        }

        return $userModel::find($id);
    }
}
