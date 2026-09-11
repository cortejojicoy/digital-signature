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
    /*
     * NOTE: $signaturePdfTemplate is deliberately NOT declared here.
     *
     * The documented host usage gives it a default —
     *   protected string $signaturePdfTemplate = 'dtr';
     * — and PHP rejects a class property whose default differs from the
     * trait's ("define the same property ... the definition differs and is
     * considered incompatible"). Declaring it in the trait would make the
     * documented usage a fatal error. The host model owns the property;
     * signatureTemplateKey() reads it.
     */

    /**
     * The registered template key that renders this model. Exposed as a
     * method so SignatoryRouter, HasSignatories and host code can ask any
     * model for its template without reaching into protected state.
     */
    public function signatureTemplateKey(): string
    {
        if (! property_exists($this, 'signaturePdfTemplate')) {
            throw new \LogicException(sprintf(
                '[%s] uses HasPdfTemplate but declares no template. Add '
                .'protected string $signaturePdfTemplate = \'…\'; naming a registered template, '
                .'or override signatureTemplateKey().',
                static::class,
            ));
        }

        return $this->signaturePdfTemplate;
    }

    public function getSignableTitle(): string
    {
        return class_basename(static::class).' #'.$this->getKey();
    }

    public function getSignablePdfPath(): string
    {
        return app(PdfTemplateRegistry::class)
            ->get($this->signatureTemplateKey())
            ->renderFor($this);
    }

    public function getSignableId(): int|string
    {
        return $this->getKey();
    }
}
