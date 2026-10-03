<?php

namespace Kukux\DigitalSignature\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kukux\DigitalSignature\Contracts\SignatoryDefaults;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;

/**
 * Freezes who signs a document on the document itself.
 *
 * A record that is routed must keep naming the people it was routed to:
 * editing someone's "my signatories" settings next week must not change who an
 * already-routed report is waiting on. So each role is copied onto the record
 * as two columns, a key and the position printed under the name, and copying
 * stops once the record has been routed.
 *
 *   class Dtr extends Model implements Signable
 *   {
 *       use HasPdfTemplate, HasSignatories, SnapshotsSignatories;
 *
 *       public function signatoryRoles(): array { return ['in_charge']; }
 *       public function signatoryModel(): string { return Employee::class; }
 *
 *       public function inCharge(): BelongsTo { return $this->signatoryRelation('in_charge'); }
 *   }
 *
 * Columns default to `{role}_signatory_id` and `{role}_position`, which is what
 * SignatoryColumns::add() creates. Name a different key-column prefix per role
 * to keep an existing schema: `['attested' => 'attested_personnel']` reads
 * `attested_personnel_id`.
 *
 * Who fills each role comes from a SignatoryDefaults implementation the
 * document definition is given, so where defaults live stays the app's
 * business.
 */
trait SnapshotsSignatories
{
    /**
     * The roles snapshotted on this record: a list of role names, or
     * role => key-column prefix for an existing schema.
     *
     * @return array<int|string, string>
     */
    abstract public function signatoryRoles(): array;

    /**
     * The model a role's key points at (Personnel, Employee, User, …).
     *
     * @return class-string<Model>
     */
    abstract public function signatoryModel(): string;

    /**
     * Copy each role's default onto the record. Does nothing once the record
     * has been routed: from then on, who signs it is history.
     *
     * Doesn't save, so the caller decides when the write happens (inside the
     * routing transaction, normally).
     */
    public function snapshotSignatories(SignatoryDefaults $defaults): void
    {
        if ($this->hasBeenRoutedForSignatures()) {
            return;
        }

        foreach (array_keys($this->normalizedSignatoryRoles()) as $role) {
            $assignment = $defaults->for($this, $role);

            $this->setAttribute($this->signatoryColumn($role), $assignment?->id);
            $this->setAttribute($this->signatoryPositionColumn($role), $assignment?->position);
        }

        // A relation loaded before the snapshot still points at the old
        // person. Drop them all rather than guess which ones changed.
        if ($this->isDirty()) {
            $this->unsetRelations();
        }
    }

    /** Has any signing session ever been opened for this record? */
    public function hasBeenRoutedForSignatures(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return SigningSession::query()->forSignable($this)->exists();
    }

    /**
     * A belongsTo for one role, for the relation a slot binds to:
     *
     *   public function inCharge(): BelongsTo { return $this->signatoryRelation('in_charge'); }
     *   new SlotDefinition(key: 'in_charge', label: 'In Charge', signatory: 'inCharge', …)
     */
    public function signatoryRelation(string $role): BelongsTo
    {
        return $this->belongsTo($this->signatoryModel(), $this->signatoryColumn($role));
    }

    /** The person snapshotted in a role, or null. */
    public function signatoryFor(string $role): ?Model
    {
        $id = $this->getAttribute($this->signatoryColumn($role));

        if ($id === null) {
            return null;
        }

        return $this->signatoryRelation($role)->first();
    }

    /** @return list<string> roles with nobody snapshotted */
    public function missingSignatoryRoles(): array
    {
        return array_values(array_filter(
            array_keys($this->normalizedSignatoryRoles()),
            fn (string $role): bool => $this->getAttribute($this->signatoryColumn($role)) === null,
        ));
    }

    /**
     * Name and position per role, for the view that prints the signature
     * block: `attested_name`, `attested_position`, …
     *
     * @return array<string, string>
     */
    public function signatoryViewData(string $placeholder = 'N/A'): array
    {
        $data = [];

        foreach (array_keys($this->normalizedSignatoryRoles()) as $role) {
            $data["{$role}_name"] = SignatoryRoute::nameOf($this->signatoryFor($role)) ?? $placeholder;
            $data["{$role}_position"] = $this->getAttribute($this->signatoryPositionColumn($role)) ?? $placeholder;
        }

        return $data;
    }

    public function signatoryColumn(string $role): string
    {
        return $this->normalizedSignatoryRoles()[$role].'_id';
    }

    public function signatoryPositionColumn(string $role): string
    {
        return "{$role}_position";
    }

    /** @return array<string, string> role => key-column prefix */
    protected function normalizedSignatoryRoles(): array
    {
        $roles = [];

        foreach ($this->signatoryRoles() as $role => $prefix) {
            if (is_int($role)) {
                $roles[$prefix] = "{$prefix}_signatory";

                continue;
            }

            $roles[$role] = $prefix;
        }

        return $roles;
    }
}
