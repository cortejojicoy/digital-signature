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

class SignatureManager
{
    public function __construct(
        protected CertificateService    $certService,
        protected PdfSignerService      $pdfSigner,
        protected DuplicateSignatureGuard $duplicateGuard,
        protected CrlValidator          $crlValidator,
        protected DocumentIntegrity     $documentIntegrity,
    ) {}

    /**
     * Store a raw signature image (base64 data URI or UploadedFile).
     * Returns a pending Signature record.
     *
     * Security checks performed here:
     *   - Cross-user duplicate image hash (forgery detection)
     *   - Document pre-signing hash captured for audit trail
     *   - UUID assigned for tamper-evident request tracking
     */
    public function store(
        int                     $userId,
        string|UploadedFile     $input,
        string                  $source,      // 'draw' | 'upload'
        ?Signable               $signable = null,
        ?array                  $position = null,
    ): Signature {
        $disk = Storage::disk(config('signature.storage_disk'));
        $dir  = config('signature.signatures_path');

        if ($input instanceof UploadedFile) {
            $path  = $input->store($dir, config('signature.storage_disk'));
            $bytes = file_get_contents($input->getRealPath());
        } else {
            // Strip base64 data URI header before decoding
            $bytes = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $input));
            $path  = $dir . '/' . uniqid('sig_', true) . '.png';
            $disk->put($path, $bytes);
        }

        $hash = hash(config('signature.hash_algo'), $bytes);

        // Security: reject if the exact image hash is already stored under another user.
        // This catches the screenshot/copy-paste forgery scenario.
        $this->duplicateGuard->check($hash, $userId);

        // Capture the document hash before signing so we can prove later that
        // the signer was presented exactly this version of the document.
        $documentHash = null;
        if ($signable !== null) {
            $documentHash = $this->documentIntegrity->hash($signable->getSignablePdfPath());
        }

        $sig = Signature::create([
            'uuid'          => (string) Str::uuid(),
            'user_id'       => $userId,
            'image_path'    => $path,
            'image_hash'    => $hash,
            'document_hash' => $documentHash,
            'source'        => $source,
            'status'        => 'pending',
            'signable_type' => $signable ? get_class($signable) : null,
            'signable_id'   => $signable?->getSignableId(),
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
     *
     * Security checks performed here:
     *   - CRL validation: verifies the certificate has not been revoked
     *   - Cryptographic PKCS#7 signature embedded in the PDF (via FpdiDriver)
     *   - Signed-document hash captured for post-signing integrity verification
     */
    public function embedAndFinalize(Signature $signature, string $userPassword): void
    {
        $cert     = $this->certService->getOrCreate($signature->user_id, $userPassword);
        $certData = $this->certService->load($cert, $userPassword);

        // Security: validate certificate is not on a CRL before signing
        $this->crlValidator->validate($certData);

        $signedPath = $this->pdfSigner->sign($signature, $certData);

        // Capture hash of the completed signed PDF for audit / tamper detection
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
