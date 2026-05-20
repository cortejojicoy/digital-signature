<?php

namespace Kukux\DigitalSignature\Filament\Pages;

use Filament\Pages\Page;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * Filament page that hosts the React-driven placement designer.
 *
 * The page itself is intentionally thin — it resolves the template
 * by key from the URL and renders a Blade view that contains the
 * React mount element. All interactivity lives in the React island,
 * which talks to PdfTemplateDesignerController over JSON.
 */
class PdfTemplateDesigner extends Page
{
    protected static string $view = 'signature::filament.pages.pdf-template-designer';

    protected static ?string $slug = 'signature-templates/{templateKey}/design';

    protected static bool $shouldRegisterNavigation = false;

    public string $templateKey;

    public ?string $templateLabel = null;

    public function mount(string $templateKey): void
    {
        $registry = app(PdfTemplateRegistry::class);

        $template = $registry->find($templateKey)
            ?? abort(404, "No PdfTemplate registered with key [{$templateKey}]");

        $this->templateKey   = $template->key();
        $this->templateLabel = $template->label();
    }

    public function getTitle(): string
    {
        return $this->templateLabel
            ? "Placement designer — {$this->templateLabel}"
            : 'Placement designer';
    }
}
