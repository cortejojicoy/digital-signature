<?php

namespace Kukux\DigitalSignature\Documents;

/**
 * The definition used for a record that has no registered document of its
 * own: route it with its template and the stock checks, nothing more.
 *
 * This is what RequestSignaturesAction routes through, so a record page that
 * never declared a SignableDocument still goes down the same path as one that
 * did.
 */
final class TemplateDocument extends AbstractSignableDocument
{
    public function __construct(protected string $templateKey)
    {
    }

    public function template(): string
    {
        return $this->templateKey;
    }
}
