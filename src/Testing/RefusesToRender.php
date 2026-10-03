<?php

namespace Kukux\DigitalSignature\Testing;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\PdfTemplate;

/**
 * Stands in for a template after routing: same key and slots, but any render
 * fails the test.
 *
 * @internal
 */
final class RefusesToRender implements PdfTemplate
{
    public function __construct(private PdfTemplate $template)
    {
    }

    public function key(): string
    {
        return $this->template->key();
    }

    public function label(): string
    {
        return $this->template->label();
    }

    public function slots(): array
    {
        return $this->template->slots();
    }

    public function renderSample(): string
    {
        throw new \LogicException('The template was rendered after the document had been routed.');
    }

    public function renderFor(Model $record): string
    {
        throw new \LogicException('The template was rendered after the document had been routed.');
    }
}
