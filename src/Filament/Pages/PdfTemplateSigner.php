<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * Filament page that hosts the React-driven signing experience.
 *
 * Distinct from PdfTemplateDesigner: the designer is for admins
 * configuring slot coordinates per template; the signer is for end
 * users placing their personal signature on a specific PDF and
 * persisting the signed copy.
 *
 * Slug carries both the template key and the signature UUID so the
 * page knows which template's PDF to render and which signature image
 * to drop onto it. The actual Signable (the host-app record being
 * signed) is passed via query string at sign time — see the docs for
 * how host apps build the entry URL from their own resources.
 */
class PdfTemplateSigner extends Page
{
    protected static string $view = 'signature::filament.pages.pdf-template-signer';

    protected static ?string $slug = 'signature-templates/{templateKey}/sign/{signatureUuid}';

    protected static bool $shouldRegisterNavigation = false;

    public string $templateKey;

    public string $signatureUuid;

    public ?string $templateLabel = null;

    public function mount(string $templateKey, string $signatureUuid): void
    {
        $registry = app(PdfTemplateRegistry::class);

        $template = $registry->find($templateKey)
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
    }

    public function getTitle(): string
    {
        return $this->templateLabel
            ? "Sign — {$this->templateLabel}"
            : 'Sign document';
    }
}
