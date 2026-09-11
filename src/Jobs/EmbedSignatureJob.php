<?php

namespace Kukux\DigitalSignature\Jobs;

use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmbedSignatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int     $signatureId,
        public readonly string  $userPassword,
        /**
         * Disk-relative PDF to sign. Multi-signatory sessions pass their
         * running document so the queued signature lands on top of the
         * previous one instead of on a fresh render.
         */
        public readonly ?string $sourcePdfPath = null,
    ) {}

    public function handle(SignatureManager $manager): void
    {
        $sig = Signature::findOrFail($this->signatureId);

        if (!$sig->isPending()) {
            return; // already processed or revoked
        }

        $manager->embedAndFinalize($sig, $this->userPassword, $this->sourcePdfPath);
    }

    public function failed(\Throwable $e): void
    {
        Signature::where('id', $this->signatureId)
            ->update(['status' => 'failed']);
    }
}