<?php

namespace Kukux\DigitalSignature\Filament\Pages\Concerns;

use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * Behaviour of the end-user signer page. See IsPdfTemplateDesigner for why
 * `$view` is declared by the V3/V4 subclasses rather than here.
 */
trait IsPdfTemplateSigner
{
    public string $templateKey;

    public string $signatureUuid;

    public ?string $templateLabel = null;

    /**
     * Target record id, read from ?signable= on the URL. When set, the
     * finalize call signs that specific record's PDF. When null, the
     * controller returns the validated placements in acknowledgement mode.
     */
    public ?string $signableId = null;

    /**
     * Signature request id, read from ?request= on the URL. Present when the
     * user arrived from their inbox, which binds the placement to a slot in
     * an open signing session instead of a free-form placement.
     */
    public ?string $signatureRequestId = null;

    public function mount(string $templateKey, string $signatureUuid): void
    {
        $template = app(PdfTemplateRegistry::class)->find($templateKey)
            ?? abort(404, "No PdfTemplate registered with key [{$templateKey}]");

        $signature = Signature::where('uuid', $signatureUuid)
            ->where('user_id', auth()->id())
            ->first();

        if (! $signature) {
            abort(404, 'Signature not found or not owned by the current user.');
        }

        $this->templateKey   = $template->key();
        $this->signatureUuid = $signatureUuid;
        $this->templateLabel = $template->label();

        // Stored as strings so slugs / uuids aren't coerced to int.
        $signable = request()->query('signable');
        $this->signableId = is_scalar($signable) ? (string) $signable : null;

        $requestId = request()->query('request');
        $this->signatureRequestId = is_scalar($requestId) ? (string) $requestId : null;
    }

    public function getTitle(): string
    {
        return $this->templateLabel
            ? "Sign — {$this->templateLabel}"
            : 'Sign document';
    }
}
