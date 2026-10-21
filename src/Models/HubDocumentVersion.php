<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hub mode: one version's fingerprint of a reported document; what a lookup finds by. */
class HubDocumentVersion extends Model
{
    protected $table = 'digital_signature_hub_document_versions';

    protected $fillable = ['document_id', 'number', 'sha256'];

    protected $casts = ['number' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(HubDocument::class, 'document_id');
    }
}
