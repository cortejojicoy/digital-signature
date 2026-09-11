<?php

namespace Kukux\DigitalSignature\Filament\Pages\Concerns;

use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * Behaviour of the React-driven placement designer, minus the `$view`
 * declaration — which is the one thing that cannot be shared, because
 * Filament v3 declares Page::$view static and v4/v5 declare it as an
 * instance property. Redeclaring a non-static property as static is a PHP
 * fatal, so the property lives in the V3/V4 subclasses and everything else
 * lives here.
 */
trait IsPdfTemplateDesigner
{
    public string $templateKey;

    public ?string $templateLabel = null;

    public function mount(string $templateKey): void
    {
        $template = app(PdfTemplateRegistry::class)->find($templateKey)
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
