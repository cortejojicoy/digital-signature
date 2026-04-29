<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Events\DocumentSigned;
use Kukux\DigitalSignature\Events\SignatureRevoked;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;
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
        protected CertificateService $certService,
        protected PdfSignerService $pdfSigner,
        protected DuplicateSignatureGuard $duplicateGuard,
        protected CrlValidator $crlValidator,
        protected DocumentIntegrity $documentIntegrity,
        protected SignatureMetadataService $metadataService,
    ) {}

    /**
     * Store a signature image and return a pending Signature record.
     *
     * Security pipeline:
     *   1.  Decode / save raw image bytes.
     *   2.  Normalise to PNG (converts JPEG) so metadata chunks are always supported.
     *   3.  DuplicateSignatureGuard — reject if the same image hash exists under
     *       a different user (screenshot/copy-paste forgery detection).
     *   4a. MetadataService::validateIfPresent — if the PNG already carries our
     *       tEXt metadata (i.e. it was previously exported from this system),
     *       verify the HMAC, user-id, and optionally the machine fingerprint.
     *   4b. DB cross-validation — if the PNG embeds a Sig-Record-Id, look up the
     *       original Signature record in the database and verify:
     *         • record exists and belongs to $userId
     *         • record is not revoked
     *         • stored machine_fingerprint matches the current request's fingerprint
     *       This is a second independent layer on top of the HMAC machine-lock check.
     *   5.  MetadataService::embedIntoImage — inject HMAC-signed tEXt + XMP chunks.
     *   6.  Overwrite the stored file with the metadata-enriched PNG.
     *   7.  Capture document hash for audit trail.
     *   8.  Create Signature record.
     *
     * @param  string  $deviceFp  Browser-side device fingerprint from machineFingerprint.js.
     * @param  string  $signerName  "Full Name <email>" string embedded into the PNG.
     */
    public function store(
        int $userId,
        string|UploadedFile $input,
        string $source,
        ?Signable $signable = null,
        ?array $position = null,
        string $deviceFp = '',
        string $signerName = '',
        ?string $certificatePassword = null,
    ): Signature {
        $disk = Storage::disk(config('signature.storage_disk'));
        $dir = config('signature.signatures_path');

        // ── 1. Decode raw bytes ───────────────────────────────────────────────
        if ($input instanceof UploadedFile) {
            $rawPath = $input->store($dir, config('signature.storage_disk'));
            $rawBytes = file_get_contents($input->getRealPath());
        } else {
            $rawBytes = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $input));
            $rawPath = $dir.'/'.uniqid('sig_', true).'.png';
            $disk->put($rawPath, $rawBytes);
        }

        // ── 2. Hash raw bytes for duplicate detection (before metadata changes them) ──
        $rawHash = hash(config('signature.hash_algo'), $rawBytes);

        // ── 3. Cross-user duplicate image hash check ──────────────────────────
        $this->duplicateGuard->check($rawHash, $userId);

        // ── 4a. Validate existing metadata on uploaded images ─────────────────
        //     Drawn signatures come from our canvas and will never have pre-existing
        //     metadata.  Uploaded images might be a previously exported signature.
        if ($source === 'upload') {
            $this->metadataService->validateIfPresent($rawBytes, $userId, $deviceFp);

            // ── 4b. DB cross-validation ───────────────────────────────────────
            //     If the PNG carries a Sig-Record-Id, look up the original DB record
            //     and verify the machine fingerprint independently of the PNG's own
            //     Sig-Machine-Hash.  This catches cases where an attacker somehow
            //     replays a valid HMAC from a different network context.
            $embeddedMeta = $this->metadataService->readMeta($rawBytes);
            $embeddedRecordId = $embeddedMeta['Sig-Record-Id'] ?? '';

            if ($embeddedRecordId !== '') {
                $this->validateAgainstDbRecord($embeddedRecordId, $userId, $deviceFp);
            }
        }

        // ── 5 + 6. Generate UUID early so it is embedded inside the PNG ───────
        //     The UUID is included in the HMAC, so it cannot be altered later.
        $uuid = (string) Str::uuid();

        $enrichedBytes = $this->metadataService->embedIntoImage(
            $rawBytes, $userId, $deviceFp, $signerName, $uuid,
        );

        // The enriched file is always PNG; update path extension if needed
        $enrichedPath = preg_replace('/\.(jpe?g)$/i', '.png', $rawPath);
        $disk->put($enrichedPath, $enrichedBytes);

        if ($enrichedPath !== $rawPath) {
            $disk->delete($rawPath); // remove original JPEG
        }

        // ── 7. Final image hash (of what is actually on disk) ─────────────────
        $imageHash = hash(config('signature.hash_algo'), $enrichedBytes);
        // Use the same formula as Sig-Machine-Hash in the PNG (no IP — see
        // SignatureMetadataService::computeMachineHash for rationale).
        $machineFingerprint = $this->computeFingerprint($userId, $deviceFp);

        // ── 8. Document pre-signing hash ──────────────────────────────────────
        $documentHash = $signable !== null
            ? $this->documentIntegrity->hash($signable->getSignablePdfPath())
            : null;

        // ── 9. Create record (UUID was already embedded in the PNG above) ─────
        $sig = Signature::create([
            'uuid' => $uuid,
            'user_id' => $userId,
            'image_path' => $enrichedPath,
            'image_hash' => $imageHash,
            'document_hash' => $documentHash,
            'machine_fingerprint' => $machineFingerprint,
            'source' => $source,
            'status' => 'pending',
            'signable_type' => $signable ? get_class($signable) : null,
            'signable_id' => $signable?->getSignableId(),
            'certificate_password' => $certificatePassword,
        ]);

        if ($position) {
            SignaturePosition::create(array_merge(['signature_id' => $sig->id], $position));
        }

        return $sig;
    }

    /**
     * Create a new pending Signature record for a document-signing event,
     * reusing an already-trusted Signature owned by the signer.
     *
     * The source signature has already passed the upload-validation pipeline
     * when it was first registered; re-running it here would re-check the
     * device fingerprint against the *current* request and fail whenever the
     * user signs from any context other than the one in which they drew the
     * signature. That's the wrong contract for a server-side reuse: the
     * binding we actually care about is "the authenticated user owns this
     * signature", which we enforce explicitly below.
     *
     * @throws ForgedSignatureException
     */
    public function storeForDocument(
        Signature $source,
        int $signerUserId,
        Signable $signable,
        ?array $position = null,
    ): Signature {
        if ((int) $source->user_id !== $signerUserId) {
            throw new ForgedSignatureException(
                'You can only sign documents with a signature registered to your own account.'
            );
        }

        if ($source->isRevoked()) {
            throw new ForgedSignatureException(
                'This signature has been revoked and can no longer be used.'
            );
        }

        $documentHash = $this->documentIntegrity->hash($signable->getSignablePdfPath());

        $sig = Signature::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $signerUserId,
            'image_path' => $source->image_path,
            'image_hash' => $source->image_hash,
            'document_hash' => $documentHash,
            'machine_fingerprint' => $source->machine_fingerprint,
            'source' => $source->source,
            'status' => 'pending',
            'signable_type' => get_class($signable),
            'signable_id' => $signable->getSignableId(),
            'certificate_password' => $source->getCertificatePassword(),
        ]);

        if ($position) {
            SignaturePosition::create(array_merge(['signature_id' => $sig->id], $position));
        }

        return $sig;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Cross-check an uploaded PNG against its original database record.
     *
     * Called only when the PNG embeds a Sig-Record-Id.  Verifies:
     *   1. The record exists and belongs to $userId.
     *   2. The record has not been revoked.
     *   3. The record's stored machine_fingerprint matches the current request.
     *
     * @throws ForgedSignatureException for ownership / existence failures.
     * @throws MachineBindingException when the machine fingerprint differs.
     */
    private function validateAgainstDbRecord(string $recordId, int $userId, string $deviceFp): void
    {
        $original = Signature::where('uuid', $recordId)->first();

        if (! $original) {
            throw new ForgedSignatureException(
                'The uploaded signature image references a record that no longer exists in this system.'
            );
        }

        if ((int) $original->user_id !== $userId) {
            throw new ForgedSignatureException(
                'The uploaded signature image was registered to a different user account.'
            );
        }

        if ($original->status === 'revoked') {
            throw new ForgedSignatureException(
                'This signature has been revoked and can no longer be used.'
            );
        }

        // Compare DB machine fingerprint against the current request.
        // Both use the same formula (userId|userAgent|deviceFp — no IP).
        $currentFingerprint = $this->computeFingerprint($userId, $deviceFp);

        if (! hash_equals($original->machine_fingerprint, $currentFingerprint)) {
            throw new MachineBindingException(
                'This signature image is registered to a different device. '
                .'Please draw a new signature on this device.'
            );
        }
    }

    /**
     * Compute the machine fingerprint stored in the DB and embedded in the PNG.
     * Intentionally excludes IP address — see SignatureMetadataService::computeMachineHash.
     */
    private function computeFingerprint(int $userId, string $deviceFp): string
    {
        return hash('sha256', implode('|', [
            (string) $userId,
            request()->userAgent() ?? '',
            $deviceFp,
        ]));
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
        $cert = $this->certService->getOrCreate($signature->user_id, $userPassword);
        $certData = $this->certService->load($cert, $userPassword);

        $this->crlValidator->validate($certData);

        $signedPath = $this->pdfSigner->sign($signature, $certData);

        $signedDocumentHash = $this->documentIntegrity->hash($signedPath);

        $signature->update([
            'signed_document_path' => $signedPath,
            'signed_document_hash' => $signedDocumentHash,
            'status' => 'signed',
            'signed_at' => now(),
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
