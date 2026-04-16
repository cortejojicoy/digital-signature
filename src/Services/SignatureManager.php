<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureRevoked;
use Kukux\DigitalSignature\Jobs\EmbedSignatureJob;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignaturePosition;
use Kukux\DigitalSignature\Security\CrlValidator;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Security\DuplicateSignatureGuard;
use Kukux\DigitalSignature\Security\SignatureMetadataService;

class SignatureManager
{
    public function __construct(
        protected CertificateService      $certService,
        protected PdfSignerService        $pdfSigner,
        protected DuplicateSignatureGuard $duplicateGuard,
        protected CrlValidator            $crlValidator,
        protected DocumentIntegrity       $documentIntegrity,
        protected SignatureMetadataService $metadataService,
    ) {}

    /**
     * Store a signature image and return a pending Signature record.
     *
     * Security pipeline:
     *   1. Decode / save raw image bytes.
     *   2. Normalise to PNG (converts JPEG) so metadata chunks are always supported.
     *   3. DuplicateSignatureGuard — reject if the same image hash exists under
     *      a different user (screenshot/copy-paste forgery detection).
     *   4. MetadataService::validateIfPresent — if the PNG already carries our
     *      tEXt metadata (i.e. it was previously exported from this system),
     *      verify the HMAC, user-id, and optionally the machine fingerprint.
     *   5. MetadataService::embedIntoImage — inject HMAC-signed tEXt chunks
     *      (userId, machine fingerprint, timestamp) into the PNG bytes.
     *   6. Overwrite the stored file with the metadata-enriched PNG.
     *   7. Capture document hash for audit trail.
     *   8. Create Signature record.
     *
     * @param string $deviceFp  Browser-side device fingerprint from machineFingerprint.js.
     */
    public function store(
        int                 $userId,
        string|UploadedFile $input,
        string              $source,      // 'draw' | 'upload'
        ?Signable           $signable  = null,
        ?array              $position  = null,
        string              $deviceFp  = '',
        string              $signerName = '',
    ): Signature {
        $disk = Storage::disk(config('signature.storage_disk'));
        $dir  = config('signature.signatures_path');

        // ── 1. Decode raw bytes ───────────────────────────────────────────────
        if ($input instanceof UploadedFile) {
            $rawPath  = $input->store($dir, config('signature.storage_disk'));
            $rawBytes = file_get_contents($input->getRealPath());
        } else {
            $rawBytes = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $input));
            $rawPath  = $dir . '/' . uniqid('sig_', true) . '.png';
            $disk->put($rawPath, $rawBytes);
        }

        // ── 2. Hash raw bytes for duplicate detection (before metadata changes them) ──
        $rawHash = hash(config('signature.hash_algo'), $rawBytes);

        // ── 3. Cross-user duplicate image hash check ──────────────────────────
        $this->duplicateGuard->check($rawHash, $userId);

        // ── 4. Validate existing metadata on uploaded images ──────────────────
        //    Drawn signatures come from our canvas and will never have pre-existing
        //    metadata.  Uploaded images might be a previously exported signature.
        if ($source === 'upload') {
            $this->metadataService->validateIfPresent($rawBytes, $userId, $deviceFp);
        }

        // ── 5 + 6. Embed metadata and overwrite stored file ───────────────────
        $enrichedBytes = $this->metadataService->embedIntoImage($rawBytes, $userId, $deviceFp, $signerName);

        // The enriched file is always PNG; update path extension if needed
        $enrichedPath = preg_replace('/\.(jpe?g)$/i', '.png', $rawPath);
        $disk->put($enrichedPath, $enrichedBytes);

        if ($enrichedPath !== $rawPath) {
            $disk->delete($rawPath); // remove original JPEG
        }

        // ── 7. Final image hash (of what is actually on disk) ─────────────────
        $imageHash       = hash(config('signature.hash_algo'), $enrichedBytes);
        $machineFingerprint = hash('sha256', implode('|', [
            (string) $userId,
            request()->userAgent() ?? '',
            request()->ip()        ?? '',
            $deviceFp,
        ]));

        // ── 8. Document pre-signing hash ──────────────────────────────────────
        $documentHash = $signable !== null
            ? $this->documentIntegrity->hash($signable->getSignablePdfPath())
            : null;

        // ── 9. Create record ──────────────────────────────────────────────────
        $sig = Signature::create([
            'uuid'                => (string) Str::uuid(),
            'user_id'             => $userId,
            'image_path'          => $enrichedPath,
            'image_hash'          => $imageHash,
            'document_hash'       => $documentHash,
            'machine_fingerprint' => $machineFingerprint,
            'source'              => $source,
            'status'              => 'pending',
            'signable_type'       => $signable ? get_class($signable) : null,
            'signable_id'         => $signable?->getSignableId(),
        ]);

        if ($position) {
            SignaturePosition::create(array_merge(['signature_id' => $sig->id], $position));
        }

        return $sig;
    }

    /**
     * Dispatch the signing job to the configured queue.
     */
    public function sign(Signature $signature, string $userPassword): void
    {
        EmbedSignatureJob::dispatch($signature->id, $userPassword)
            ->onQueue(config('signature.queue'))
            ->onConnection(config('signature.queue_connection'));
    }

    /**
     * Synchronous signing — called inside EmbedSignatureJob.
     */
    public function embedAndFinalize(Signature $signature, string $userPassword): void
    {
        $cert     = $this->certService->getOrCreate($signature->user_id, $userPassword);
        $certData = $this->certService->load($cert, $userPassword);

        $this->crlValidator->validate($certData);

        $signedPath = $this->pdfSigner->sign($signature, $certData);

        $signedDocumentHash = $this->documentIntegrity->hash($signedPath);

        $signature->update([
            'signed_document_path'    => $signedPath,
            'signed_document_hash'    => $signedDocumentHash,
            'status'                  => 'signed',
            'signed_at'               => now(),
            'certificate_fingerprint' => $cert->fingerprint,
        ]);

        event(new DocumentSigned($signature));
    }

    public function revoke(Signature $signature): void
    {
        $signature->update(['status' => 'revoked', 'revoked_at' => now()]);
        event(new SignatureRevoked($signature));
    }
}
