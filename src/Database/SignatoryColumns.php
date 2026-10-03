<?php

namespace Kukux\DigitalSignature\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * The columns SnapshotsSignatories reads, so every signable table looks alike:
 *
 *   Schema::create('dtrs', function (Blueprint $table) {
 *       $table->id();
 *       ...
 *       SignatoryColumns::add($table, ['in_charge'], references: 'employees');
 *   });
 *
 * gives `in_charge_signatory_id` (nullable, null on delete) and
 * `in_charge_position`.
 */
final class SignatoryColumns
{
    /**
     * @param  list<string>  $roles
     * @param  string  $references  the table signatories live in
     */
    public static function add(Blueprint $table, array $roles, string $references = 'users'): void
    {
        foreach ($roles as $role) {
            $table->foreignId("{$role}_signatory_id")->nullable()->constrained($references)->nullOnDelete();
            $table->string("{$role}_position")->nullable();
        }
    }

    /** @param  list<string>  $roles */
    public static function drop(Blueprint $table, array $roles): void
    {
        foreach ($roles as $role) {
            $table->dropConstrainedForeignId("{$role}_signatory_id");
            $table->dropColumn("{$role}_position");
        }
    }
}
