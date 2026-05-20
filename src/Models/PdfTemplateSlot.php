<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted coordinates for a single (template, slot) pair.
 *
 * One row per slot per template. When an admin uses the placement
 * designer to drag a slot, that change writes to this table. Sign-time
 * code reads from here to populate the per-Signature `signature_positions`
 * row before invoking the PDF signer driver.
 */
class PdfTemplateSlot extends Model
{
    protected $table = 'digital_pdf_template_slots';

    protected $fillable = [
        'template_key',
        'slot_key',
        'page',
        'x',
        'y',
        'width',
        'height',
    ];

    protected $casts = [
        'page'   => 'integer',
        'x'      => 'float',
        'y'      => 'float',
        'width'  => 'float',
        'height' => 'float',
    ];
}
