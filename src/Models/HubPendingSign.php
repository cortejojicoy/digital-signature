<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Client mode: a signature this app is waiting on the hub for. */
class HubPendingSign extends Model
{
    protected $table = 'digital_signature_hub_pending_signs';

    protected $fillable = [
        'hub_request_id', 'agent_job_uuid', 'user_id', 'signature_request_id', 'idempotency_key',
        'document_hash', 'source_hash', 'specimen_hash', 'prepared_path', 'payload',
        'status', 'reason', 'cms', 'attempts',
    ];

    protected $casts = [
        'payload'  => 'array',
        'attempts' => 'integer',
    ];

    protected $hidden = ['cms'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }
}
