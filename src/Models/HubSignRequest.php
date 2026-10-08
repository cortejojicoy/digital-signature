<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document hash an app asked the hub to sign (hub mode). */
class HubSignRequest extends Model
{
    protected $table = 'digital_signature_hub_sign_requests';

    protected $fillable = [
        'uuid', 'app_id', 'personnel_key', 'user_id', 'signature_id', 'agent_job_id',
        'idempotency_key', 'document_hash', 'specimen_hash', 'title', 'slot', 'capacity',
        'status', 'refusal_reason', 'cms', 'certificate_fingerprint', 'signed_at', 'expires_at',
    ];

    protected $casts = [
        'signed_at'  => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['cms'];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }
}
