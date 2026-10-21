<?php

namespace Kukux\DigitalSignature\Verification;

/**
 * What the record says about one file, by its fingerprint.
 *
 * The verdict is the server's half only: whether these bytes are a version
 * this system issued. The page combines it with the local check of the
 * file's embedded signature (resources/js/verify/pdfSignature.js), which is
 * where `invalid_signature` comes from; the server never sees the file, so it
 * never says that itself.
 */
final readonly class DocumentVerification
{
    /** The file is the last version of a session every signatory finished. */
    public const COMPLETE = 'complete';

    /** The file is the latest version, but the session still has unsigned slots. */
    public const IN_PROGRESS = 'in_progress';

    /** The file is a real, earlier version; later signatures exist. */
    public const SUPERSEDED = 'superseded';

    /** The file is the last version of a session that was cancelled or expired. */
    public const WITHDRAWN = 'withdrawn';

    /** The file isn't a version, but its signed prefix is: changed after signing. */
    public const SIGNATURE_VALID_FILE_CHANGED = 'signature_valid_file_changed';

    /** Neither fingerprint matches anything this system issued. */
    public const NOT_ISSUED = 'not_issued';

    /**
     * @param  array{title: ?string, version: int, versions: int, signed: int, required: int, status: string}|null  $document
     * @param  list<array{slot: string, label: string, state: string, signer: ?string, signed_at: ?string, certificate: ?string}>  $slots
     */
    public function __construct(
        public string $verdict,
        public ?array $document = null,
        public array $slots = [],
        public ?string $issuer = null,
    ) {
    }

    public static function notIssued(): self
    {
        return new self(self::NOT_ISSUED);
    }

    public function isRecord(): bool
    {
        return $this->verdict !== self::NOT_ISSUED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict'  => $this->verdict,
            // At the hub, the app that reported the document; otherwise this app.
            'issuer'   => $this->issuer ?? config('app.name'),
            'document' => $this->document,
            'slots'    => $this->slots,
        ];
    }
}
