<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kukux\DigitalSignature\Verification\DocumentSnapshot;

/**
 * Hub mode: a document an app routed for signatures, as the app last
 * reported it (PUT /signature/hub/api/v1/documents/{uuid}). Kept so the hub's
 * verify page and Kukux Sign Agent can verify a PDF from any app. The hub
 * never holds the PDF, only its versions' fingerprints.
 */
class HubDocument extends Model
{
    protected $table = 'digital_signature_hub_documents';

    protected $fillable = ['app_id', 'uuid', 'title', 'status', 'report', 'reported_at'];

    protected $casts = [
        'report'      => 'array',
        'reported_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HubDocumentVersion::class, 'document_id')->orderBy('number');
    }

    public function snapshot(): DocumentSnapshot
    {
        return DocumentSnapshot::fromArray((array) $this->report);
    }
}
