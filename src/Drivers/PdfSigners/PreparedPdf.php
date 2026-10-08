<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

/** A stamped PDF with an empty signature, waiting for its CMS. */
final class PreparedPdf
{
    /**
     * @param  string  $path  Disk-relative, on signature.storage_disk.
     * @param  string  $digest  SHA-256 hex of the bytes /ByteRange covers.
     * @param  array{0:int,1:int,2:int,3:int}  $byteRange
     * @param  int  $placeholderLength  Hex characters available for the CMS.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $digest,
        public readonly array $byteRange,
        public readonly int $placeholderLength,
    ) {}
}
