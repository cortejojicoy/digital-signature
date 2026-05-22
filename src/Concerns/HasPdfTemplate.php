<?php

namespace Kukux\DigitalSignature\Concerns;

use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * Lets a host-app Eloquent model become signable through a registered
 * PdfTemplate without writing the full Signable boilerplate.
 *
 * Use case — the model declares which template key renders it, and the
 * trait implements the Signable contract by delegating to the template:
 *
 *   class Dtr extends Model implements Signable
 *   {
 *       use HasPdfTemplate;
 *
 *       protected string $signaturePdfTemplate = 'dtr';
 *   }
 *
 * That's it. The model is now passable to
 * SignatureManager::storeForDocument() and the polymorphic
 * `signable_type` correctly stores the host model class (not a wrapper),
 * so existing polymorphic queries keep working.
 *
 * Override any of the methods to customize:
 *  - getSignableTitle()    — defaults to "<ClassName> #<id>"
 *  - getSignablePdfPath()  — calls $template->renderFor($this)
 *  - getSignableId()       — defaults to the model's primary key
 */
trait HasPdfTemplate
{
    /**
     * Key into config('signature.templates'). Override in the host model.
     */
    protected string $signaturePdfTemplate;

    public function getSignableTitle(): string
    {
        return class_basename(static::class).' #'.$this->getKey();
    }

    public function getSignablePdfPath(): string
    {
        return app(PdfTemplateRegistry::class)
            ->get($this->signaturePdfTemplate)
            ->renderFor($this);
    }

    public function getSignableId(): int|string
    {
        return $this->getKey();
    }
}
