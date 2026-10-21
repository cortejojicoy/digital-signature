<?php

namespace Kukux\DigitalSignature\Verification;

use Closure;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentHistory;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentVersion;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * One routed document as verification sees it: its versions' fingerprints
 * and, per slot, who signed it and in which version.
 *
 * It exists so the app and the hub answer a lookup by the same rules. An app
 * builds one from its own signing session (fromSession); in client mode it
 * also sends that same array to the hub (toArray), which keeps it and
 * rebuilds it to answer lookups for documents it never held (fromArray).
 * verify() is the one place a fingerprint match becomes a verdict.
 *
 * It carries only what a holder of the document could read off it: the
 * template's name, slot labels, signers' names, when they signed, and the
 * fingerprint of the certificate each signature used.
 */
final readonly class DocumentSnapshot
{
    /**
     * @param  list<array{number: int, sha256: string, created_at: ?string}>  $versions  oldest first; 0 is the frozen base
     * @param  list<array{slot: string, label: string, state: string, signer: ?string, signed_at: ?string, version: ?int, certificate_fingerprint: ?string, revoked: bool}>  $slots
     */
    public function __construct(
        public ?string $title,
        public string $status,
        public bool $expired,
        public int $required,
        public array $versions,
        public array $slots,
        public ?string $issuer = null,
    ) {
    }

    /**
     * Who issued the document, when that isn't this app: at the hub, the app
     * that reported it. Not part of toArray(); the hub knows it from the
     * report's sender, never from the report's contents.
     */
    public function issuedBy(?string $issuer): self
    {
        return new self($this->title, $this->status, $this->expired, $this->required, $this->versions, $this->slots, $issuer);
    }

    public static function fromSession(SigningSession $session): self
    {
        $versions = DocumentHistory::versionsOf($session);

        // signature id => the version that signature produced
        $producedBy = $versions
            ->filter(fn (DocumentVersion $v): bool => $v->signature !== null)
            ->mapWithKeys(fn (DocumentVersion $v): array => [$v->signature->getKey() => $v]);

        $template = app(PdfTemplateRegistry::class)->find((string) $session->template_key);
        $labels = [];

        foreach ($template?->slots() ?? [] as $slot) {
            $labels[$slot->key] = $slot->label;
        }

        $requests = $session->requests()->with('user')->get();

        $slots = $requests->map(function (SignatureRequest $request) use ($producedBy, $labels): array {
            $version = $request->signature_id !== null ? $producedBy->get($request->signature_id) : null;
            $signature = $version?->signature;

            return [
                'slot'                    => (string) $request->slot_key,
                'label'                   => $labels[$request->slot_key] ?? Str::headline((string) ($request->role ?: $request->slot_key)),
                'state'                   => match (true) {
                    $version !== null                        => 'signed',
                    $request->state === RouteState::Declined => 'declined',
                    default                                  => 'pending',
                },
                'signer'                  => $signature !== null ? ($request->user?->name ?? $signature->user?->name) : null,
                'signed_at'               => $signature !== null ? optional($signature->signed_at ?? $signature->created_at)->toIso8601String() : null,
                'version'                 => $version?->number,
                'certificate_fingerprint' => $signature?->certificate_fingerprint ?: null,
                'revoked'                 => (bool) $signature?->isRevoked(),
            ];
        })->values()->all();

        return new self(
            title: $template?->label(),
            // A session made without an explicit status holds the column's
            // default only once reloaded; that default is open.
            status: (string) ($session->status ?: SigningSession::STATUS_OPEN),
            expired: $session->hasExpired(),
            required: $requests->where('required', true)->count(),
            versions: $versions->map(fn (DocumentVersion $v): array => [
                'number'     => $v->number,
                'sha256'     => strtolower((string) $v->hash),
                'created_at' => $v->createdAt->toIso8601String(),
            ])->filter(fn (array $v): bool => $v['sha256'] !== '')->values()->all(),
            slots: $slots,
        );
    }

    /** @param  array<string, mixed>  $data  as toArray() wrote it */
    public static function fromArray(array $data): self
    {
        return new self(
            title: isset($data['title']) ? (string) $data['title'] : null,
            status: (string) ($data['status'] ?? SigningSession::STATUS_OPEN),
            expired: (bool) ($data['expired'] ?? false),
            required: (int) ($data['required'] ?? 0),
            versions: array_values(array_map(fn (array $v): array => [
                'number'     => (int) $v['number'],
                'sha256'     => strtolower((string) $v['sha256']),
                'created_at' => $v['created_at'] ?? null,
            ], (array) ($data['versions'] ?? []))),
            slots: array_values(array_map(fn (array $s): array => [
                'slot'                    => (string) $s['slot'],
                'label'                   => (string) ($s['label'] ?? $s['slot']),
                'state'                   => (string) ($s['state'] ?? 'pending'),
                'signer'                  => $s['signer'] ?? null,
                'signed_at'               => $s['signed_at'] ?? null,
                'version'                 => isset($s['version']) ? (int) $s['version'] : null,
                'certificate_fingerprint' => $s['certificate_fingerprint'] ?? null,
                'revoked'                 => (bool) ($s['revoked'] ?? false),
            ], (array) ($data['slots'] ?? []))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title'    => $this->title,
            'status'   => $this->status,
            'expired'  => $this->expired,
            'required' => $this->required,
            'versions' => $this->versions,
            'slots'    => $this->slots,
        ];
    }

    /** The version number a fingerprint is, or null. */
    public function versionOf(string $sha256): ?int
    {
        $sha256 = strtolower($sha256);

        foreach ($this->versions as $version) {
            if ($version['sha256'] === $sha256) {
                return $version['number'];
            }
        }

        return null;
    }

    /**
     * The verdict for a file that is version $number of this document.
     *
     * @param  Closure(array): string  $certificate  a slot's certificate status
     *         (valid | revoked | unknown | unchecked), asked only for slots this
     *         copy carries
     */
    public function verify(int $number, bool $changedAfterSigning, Closure $certificate): DocumentVerification
    {
        $latest = max(array_column($this->versions, 'number') ?: [0]);

        $verdict = match (true) {
            $changedAfterSigning                                       => DocumentVerification::SIGNATURE_VALID_FILE_CHANGED,
            $number < $latest                                          => DocumentVerification::SUPERSEDED,
            $this->status === SigningSession::STATUS_COMPLETE          => DocumentVerification::COMPLETE,
            $this->status === SigningSession::STATUS_OPEN && ! $this->expired => DocumentVerification::IN_PROGRESS,
            default                                                    => DocumentVerification::WITHDRAWN,
        };

        $carried = 0;

        $slots = array_map(function (array $slot) use ($number, $certificate, &$carried): array {
            // A slot signed in a later version is not on this copy.
            $onThisCopy = $slot['state'] === 'signed' && $slot['version'] !== null && $slot['version'] <= $number;

            if ($onThisCopy) {
                $carried++;
            }

            return [
                'slot'        => $slot['slot'],
                'label'       => $slot['label'],
                'state'       => $onThisCopy ? 'signed' : ($slot['state'] === 'signed' ? 'signed_later' : $slot['state']),
                'signer'      => $onThisCopy ? $slot['signer'] : null,
                'signed_at'   => $onThisCopy ? $slot['signed_at'] : null,
                'certificate' => $onThisCopy ? $certificate($slot) : null,
            ];
        }, $this->slots);

        return new DocumentVerification($verdict, issuer: $this->issuer, document: [
            'title'    => $this->title,
            'version'  => $number,
            'versions' => $latest,
            'signed'   => $carried,
            'required' => $this->required,
            'status'   => $this->status,
        ], slots: $slots);
    }
}
