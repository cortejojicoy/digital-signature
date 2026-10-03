<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Every version of a record's routed document, oldest first.
 *
 * Grouped by signing session: a document that was routed, withdrawn and
 * routed again has two runs of versions, each starting from its own base
 * render. Built from rows the package already keeps (the session's base and
 * each signature's signed output), so nothing new is stored to have it.
 */
final class DocumentHistory
{
    /** @param  Collection<int, DocumentVersion>  $versions */
    public function __construct(
        public readonly Model $record,
        private readonly Collection $versions,
    ) {
    }

    public static function for(Model $record): self
    {
        $versions = collect();

        $sessions = SigningSession::query()
            ->forSignable($record)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $versions = $versions->merge(self::versionsOf($session));
        }

        return new self($record, $versions->values());
    }

    /** @return Collection<int, DocumentVersion> */
    public static function versionsOf(SigningSession $session): Collection
    {
        $versions = collect([new DocumentVersion(
            session: $session,
            number: 0,
            path: $session->base_document_path,
            hash: $session->base_document_hash,
            signature: null,
            createdAt: CarbonImmutable::parse($session->created_at),
        )]);

        // Creation order is signing order: each signature is created on top
        // of the running document the one before it produced.
        $signed = Signature::query()
            ->where('signing_session_id', $session->id)
            ->where('status', 'signed')
            ->whereNotNull('signed_document_path')
            ->with('user')
            ->orderBy('id')
            ->get();

        foreach ($signed->values() as $index => $signature) {
            $versions->push(new DocumentVersion(
                session: $session,
                number: $index + 1,
                path: $signature->signed_document_path,
                hash: $signature->signed_document_hash,
                signature: $signature,
                createdAt: CarbonImmutable::parse($signature->signed_at ?? $signature->created_at),
            ));
        }

        return $versions;
    }

    /** @return Collection<int, DocumentVersion> */
    public function versions(): Collection
    {
        return $this->versions;
    }

    /** @return Collection<int, DocumentVersion> */
    public function forSession(SigningSession $session): Collection
    {
        return $this->versions->filter(fn (DocumentVersion $v): bool => $v->session->is($session))->values();
    }

    public function find(SigningSession $session, int $number): ?DocumentVersion
    {
        return $this->forSession($session)->first(fn (DocumentVersion $v): bool => $v->number === $number);
    }

    /** The version a signature produced: the copy that signatory signed. */
    public function producedBy(Signature $signature): ?DocumentVersion
    {
        return $this->versions->first(fn (DocumentVersion $v): bool => $v->signature?->is($signature) ?? false);
    }

    public function latest(): ?DocumentVersion
    {
        return $this->versions->last();
    }

    public function isEmpty(): bool
    {
        return $this->versions->isEmpty();
    }
}
