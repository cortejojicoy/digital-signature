<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A move of a person's signature from one hub account (computer) to another. */
class Transfer extends Model
{
    protected $table = 'digital_signature_transfers';

    protected $fillable = [
        'uuid', 'personnel_key', 'from_user_id', 'to_user_id', 'agent_job_id',
        'status', 'decided_by', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'to_user_id');
    }

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }
}
