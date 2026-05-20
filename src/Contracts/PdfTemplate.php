<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

/**
 * Represents a Blade-rendered PDF that signatures can be attached to.
 *
 * Host apps register one of these per kind of PDF they produce (DTR,
 * payslip, contract, etc.). The plugin uses it for three things:
 *
 *  - Render a sample PDF the placement designer can preview.
 *  - Render the final PDF for a specific host-app record at sign time.
 *  - Declare the named slots (signature drop zones) that the designer
 *    surfaces as snap targets and that the slot table persists per-template
 *    coordinates against.
 */
interface PdfTemplate
{
    /**
     * Short, stable identifier used as the `template_key` on
     * `digital_pdf_template_slots`. Pick something url-safe and don't
     * change it after rows exist — e.g. "dtr", "payslip-monthly".
     */
    public function key(): string;

    /**
     * Human-readable name for the template list in the admin UI.
     */
    public function label(): string;

    /**
     * Slots available on this template, in display order.
     *
     * @return list<SlotDefinition>
     */
    public function slots(): array;

    /**
     * Materialize a sample render of this template for the placement
     * designer to preview. Should be deterministic so the designer can
     * cache the rasterized pages.
     *
     * Returns an absolute filesystem path to the PDF.
     */
    public function renderSample(): string;

    /**
     * Materialize the production PDF for a specific host-app record.
     * Called at sign time. Returns an absolute filesystem path.
     */
    public function renderFor(Model $record): string;
}
