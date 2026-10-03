<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns whoever a slot resolved to into the login that owns signatures.
 *
 * Signatures belong to users: a registered signature, its certificate and
 * every request are keyed by `user_id`. Host records rarely tag users
 * directly, though. A report names a Personnel row, a leave form names an
 * Employee, a travel request names someone in an HR directory. This is the
 * one place that crossing is made, so a slot can bind to whatever the record
 * actually holds (`signatory: 'attestedPersonnel'`) and the package still
 * routes to a login.
 *
 * Returning null means "this person cannot sign here", and the slot is routed
 * as unassigned. It is never guessed: the default mapper refuses anything that
 * isn't already a login, because treating a Personnel id as a user id would
 * route the document to whichever user happens to share that number.
 *
 * Bind your own in a service provider:
 *
 *   $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
 */
interface SignatoryUserMapper
{
    public function toUser(Model $resolved): ?Authenticatable;
}
