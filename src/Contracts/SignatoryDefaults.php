<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Signatories\SignatoryAssignment;

/**
 * Who fills a role on a document when it is first filed.
 *
 * SnapshotsSignatories copies these onto the record, and stops copying once
 * the record has been routed: changing someone's settings later must not
 * change who an already-routed document is waiting on.
 *
 * Where the answer comes from is the app's business, which is why this is a
 * contract: a per-person "my signatories" settings row, an org chart, the
 * employee's supervisor, an HR directory.
 */
interface SignatoryDefaults
{
    /** Null when nobody is configured for this role. */
    public function for(Model $record, string $role): ?SignatoryAssignment;
}
