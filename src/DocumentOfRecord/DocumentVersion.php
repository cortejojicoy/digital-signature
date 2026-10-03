<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Events\DocumentTampered;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Support\DiskPath;

/**
 * One file in a routed document's history.
 *
 * Version 0 is the render everybody signs; version N is the document right
 * after the Nth signature. Each is a separate file nothing overwrites, with
 * the hash recorded when it was produced, so a signatory can open the exact
 * copy they signed and check it hasn't changed since.
 */
final class DocumentVersion
{
    private ?bool $verified = null;

    public function __construct(
        public readonly SigningSession $session,
        public readonly int $number,
        public readonly string $path,
        public readonly ?string $hash,
        public readonly ?Signature $signature,
        public readonly CarbonImmutable $createdAt,
    ) {
    }

    public function isBase(): bool
    {
        return $this->number === 0;
    }

    /** Who produced this version, or null for the base render. */
    public function signerId(): int|string|null
    {
        return $this->signature?->user_id;
    }

    public function exists(): bool
    {
        return $this->disk()->exists(DiskPath::relative($this->path));
    }

    /**
     * Re-hash the file and compare it with the hash recorded when it was
     * produced. False for a missing file, and for a version with no recorded
     * hash, since there's nothing to vouch for it.
     */
    public function verify(): bool
    {
        if ($this->verified !== null) {
            return $this->verified;
        }

        if ($this->hash === null || ! $this->exists()) {
            return $this->verified = false;
        }

        $actual = hash(
            config('signature.hash_algo', 'sha256'),
            (string) $this->disk()->get(DiskPath::relative($this->path)),
        );

        return $this->verified = hash_equals($this->hash, $actual);
    }

    public function contents(): string
    {
        return (string) $this->disk()->get(DiskPath::relative($this->path));
    }

    /**
     * The bytes, for showing to someone. Checks them first: a file that no
     * longer matches what was signed is still served (hiding it helps nobody
     * work out what happened) but raises DocumentTampered.
     */
    public function serve(): string
    {
        if (! $this->verify()) {
            event(new DocumentTampered($this));
        }

        return $this->contents();
    }

    /** Where the browser opens this version. */
    public function url(bool $download = false): string
    {
        $parameters = ['session' => $this->session->uuid, 'version' => $this->number];

        if ($download) {
            $parameters['download'] = 1;
        }

        return route('signature.documents.version', $parameters);
    }

    private function disk()
    {
        return Storage::disk(config('signature.storage_disk'));
    }
}
