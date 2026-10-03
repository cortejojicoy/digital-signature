<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentVersion;

/**
 * Who may see a routed document's history, and whether its record may go.
 *
 * Bind your own when the app's rules differ from the default
 * (DefaultDocumentOfRecordGate): an accounting office that may read every
 * travel request, say.
 */
interface DocumentOfRecordGate
{
    /** May this user see the record's current document and its history? */
    public function canView(?Authenticatable $user, Model $record): bool;

    /**
     * May this user open this one version? A signatory can always open the
     * version their own signature produced, even after losing access to the
     * record: it is the copy of what they signed.
     */
    public function canViewVersion(?Authenticatable $user, Model $record, DocumentVersion $version): bool;

    /**
     * May a record that has been routed be deleted? Its signed versions are a
     * history people are entitled to; the default says no.
     */
    public function canDelete(Model $record): bool;
}
