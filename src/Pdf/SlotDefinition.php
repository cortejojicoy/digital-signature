<?php

namespace Kukux\DigitalSignature\Pdf;

use Closure;

/**
 * Declarative description of a named drop zone on a PdfTemplate.
 *
 * A slot answers two questions:
 *
 *   WHERE  — the rectangle on the page (defaults here; persisted
 *            coordinates live in `digital_pdf_template_slots`).
 *   WHOSE  — which person fills it, via the `signatory` binding. A slot
 *            with no binding is a free placement the signer positions
 *            themselves; a slot with one is *routed* to whoever the host
 *            record tags in that role.
 *
 * The routed form is what turns "Prepared by / Attested by / Noted by" into
 * an automatic flow: the template declares the roles, the record names the
 * people, and SignatoryRouter joins the two.
 */
final readonly class SlotDefinition
{
    public function __construct(
        /** Stable identifier within the template, e.g. "employee", "attested_by". */
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
         * Whether this slot must be filled before the signing session can
         * complete. Designer + signing UI use this to surface required-field
         * validation.
         */
        public bool $required = false,

        /**
         * How to find the person who fills this slot, given the host record.
         * Accepts:
         *   - a relation name      — 'attestedBy'      → $record->attestedBy
         *   - a foreign key column — 'attested_by_id'  → User::find($record->attested_by_id)
         *   - a closure            — fn (Model $r) => $r->department->head
         *   - an invokable class   — \App\Signatories\DepartmentHead::class
         *
         * Null means the slot is unrouted: no particular person owns it and
         * whoever opens the signer page places their own signature.
         */
        public string|Closure|null $signatory = null,

        /**
         * Optional semantic role name, used by policies, notifications and
         * delegation grants (a grant is scoped to a template + role). Falls
         * back to the slot key when not given — see role().
         */
        public ?string $role = null,

        /**
         * Signing order within the template, 1-based. Drives sequential
         * sessions: a slot cannot be signed while a lower-ordered required
         * slot is still outstanding. Null sorts after all numbered slots.
         */
        public ?int $order = null,
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

    /**
     * True when this slot is bound to a specific person on the record.
     */
    public function isRouted(): bool
    {
        return $this->signatory !== null;
    }

    /**
     * Role identifier used for delegation grants and policies. Defaults to
     * the slot key so an unadorned slot still has a stable role name.
     */
    public function role(): string
    {
        return $this->role ?? $this->key;
    }

    /**
     * Sort weight for sequential signing. Unordered slots sort last while
     * keeping a stable relative order.
     */
    public function sortOrder(): int
    {
        return $this->order ?? PHP_INT_MAX;
    }

    /**
     * The default placement as a position array, or null when incomplete.
     *
     * @return array{page:int,x:float,y:float,width:float,height:float}|null
     */
    public function defaultPlacement(): ?array
    {
        if (! $this->hasDefaultPlacement()) {
            return null;
        }

        return [
            'page'   => $this->defaultPage,
            'x'      => $this->defaultX,
            'y'      => $this->defaultY,
            'width'  => $this->defaultWidth,
            'height' => $this->defaultHeight,
        ];
    }
}
