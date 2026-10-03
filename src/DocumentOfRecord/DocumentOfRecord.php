<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;
use Kukux\DigitalSignature\Support\HumanList;

/**
 * The file a routed document IS: what View and Download serve once it has
 * been routed, never a fresh render.
 *
 * A re-render would drop every signature and rebuild the document from
 * today's data, showing something nobody signed. So once a record has a
 * signing session, its document is the latest version that session produced,
 * whatever happens to the data behind it afterwards.
 */
final class DocumentOfRecord
{
    public function __construct(
        public readonly Model $record,
        public readonly SigningSession $session,
        public readonly DocumentVersion $current,
        public readonly DocumentState $state,
    ) {
    }

    public static function for(Model $record): ?self
    {
        $session = SigningSession::query()
            ->forSignable($record)
            ->latest('id')
            ->first();

        if ($session === null) {
            return null;
        }

        $versions = DocumentHistory::versionsOf($session);

        return new self(
            record: $record,
            session: $session,
            current: $versions->last(),
            state: DocumentState::of($session, $versions->count() > 1),
        );
    }

    public function isFinal(): bool
    {
        return $this->state === DocumentState::Complete;
    }

    public function history(): DocumentHistory
    {
        return DocumentHistory::for($this->record);
    }

    public function contents(): string
    {
        return $this->current->serve();
    }

    public function url(bool $download = false): string
    {
        return $this->current->url($download);
    }

    /** The banner line over the document: "2 of 4 signed · waiting on Dr Reyes". */
    public function label(): string
    {
        $requests = $this->session->requests()->with('user')->get();
        $required = $requests->where('required', true);

        $waiting = HumanList::join($required
            ->filter(fn (SignatureRequest $r): bool => $r->state->isOutstanding())
            ->map(fn (SignatureRequest $r): string => SignatoryRoute::nameOf($r->user) ?? $r->role)
            ->unique()
            ->values()
            ->all()) ?: 'its signatories';

        return (string) trans('signature::routing.document_of_record.'.$this->state->value, [
            'total'   => (string) $required->count(),
            'signed'  => (string) $required->where('state', RouteState::Signed)->count(),
            'waiting' => $waiting,
            'date'    => $this->session->completed_at?->format('j M Y') ?? '',
        ]);
    }

    /** A filename that says what this is: "AR-…-signed.pdf". */
    public function downloadName(string $base): string
    {
        $base = preg_replace('/\.pdf$/i', '', $base);

        return match ($this->state) {
            DocumentState::Complete   => "{$base}-signed.pdf",
            DocumentState::Withdrawn  => "{$base}-withdrawn.pdf",
            default                   => "{$base}-in-progress.pdf",
        };
    }
}
