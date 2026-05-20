<?php

namespace Kukux\DigitalSignature\Pdf;

/**
 * Declarative description of a named drop zone on a PdfTemplate.
 *
 * Slots are the *contract* a template exposes to the designer: which
 * named regions accept a signature, what they're called, and any
 * defaults the designer should seed when no saved coordinates exist
 * yet. The persisted coordinates live in `digital_pdf_template_slots`
 * — this object only describes what's possible.
 */
final readonly class SlotDefinition
{
    public function __construct(
        /** Stable identifier within the template, e.g. "employee", "in_charge". */
        public string $key,

        /** Human-readable label shown in the designer and signing UI. */
        public string $label,

        /**
         * Optional default placement, used the first time a slot is
         * rendered before an admin has saved coordinates. All units
         * are PDF points; y is measured from the BOTTOM of the page.
         */
        public ?int $defaultPage = null,
        public ?float $defaultX = null,
        public ?float $defaultY = null,
        public ?float $defaultWidth = null,
        public ?float $defaultHeight = null,

        /**
         * Whether this slot must be filled before "Finish & Save".
         * Designer + signing UI use this to surface required-field
         * validation.
         */
        public bool $required = false,
    ) {
    }

    /**
     * True when the definition carries a usable default placement.
     */
    public function hasDefaultPlacement(): bool
    {
        return $this->defaultPage !== null
            && $this->defaultX !== null
            && $this->defaultY !== null
            && $this->defaultWidth !== null
            && $this->defaultHeight !== null;
    }
}
