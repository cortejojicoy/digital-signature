<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Kukux\DigitalSignature\Contracts\DocumentOfRecordGate;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * The default rules for a routed document's history.
 *
 *  - View: whoever the record's policy lets `view` it, plus everyone the
 *    document was routed to and whoever routed it. Signatories need no extra
 *    permission to read a document they were asked to sign.
 *  - One version: the above, or the person whose signature produced it. That
 *    copy is theirs to review even if they've since lost access to the record.
 *  - Delete: never, once routed. Bind your own gate to allow it.
 */
class DefaultDocumentOfRecordGate implements DocumentOfRecordGate
{
    public function canView(?Authenticatable $user, Model $record): bool
    {
        if ($user === null) {
            return false;
        }

        if (Gate::forUser($user)->allows('view', $record)) {
            return true;
        }

        $sessions = SigningSession::query()->forSignable($record)->pluck('id');

        if ($sessions->isEmpty()) {
            return false;
        }

        $id = $user->getAuthIdentifier();

        return SignatureRequest::query()
            ->whereIn('signing_session_id', $sessions)
            ->where('user_id', $id)
            ->exists()
            || SigningSession::query()->whereIn('id', $sessions)->where('opened_by', $id)->exists();
    }

    public function canViewVersion(?Authenticatable $user, Model $record, DocumentVersion $version): bool
    {
        if ($user !== null
            && $version->signature !== null
            && (string) $version->signature->user_id === (string) $user->getAuthIdentifier()) {
            return true;
        }

        return $this->canView($user, $record);
    }

    public function canDelete(Model $record): bool
    {
        return ! SigningSession::query()->forSignable($record)->exists();
    }
}
