<?php

namespace Kukux\DigitalSignature\Security;

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Support\DiskPath;

/**
 * Computes SHA-256 hashes of PDF files stored on the configured disk.
 *
 * Two hashes are captured per signing event (inspired by LibreSign's SignFileService):
 *   - document_hash       : hash of the original PDF before signing
 *   - signed_document_hash: hash of the output PDF after signing
 *
 * Comparing them lets you prove (a) the signer saw exactly that document and
 * (b) the signed PDF has not been tampered with after it was produced.
 */
class DocumentIntegrity
{
    /**
     * @param  string  $pdfPath  disk-relative, or absolute inside the disk root
     *   (what Signable::getSignablePdfPath() and PdfTemplate::renderFor()
     *   return). Both name the same file; see Support\DiskPath.
     */
    public function hash(string $pdfPath): string
    {
        $content = Storage::disk(config('signature.storage_disk'))->get(DiskPath::relative($pdfPath));

        if ($content === null) {
            throw new \RuntimeException("Cannot hash PDF: file not found at [{$pdfPath}].");
        }

        return hash(config('signature.hash_algo', 'sha256'), $content);
    }
}
