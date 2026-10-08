<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A usernameless "Sign in with your computer" challenge (hub mode). */
class HubLogin extends Model
{
    protected $table = 'digital_signature_hub_logins';

    protected $fillable = [
        'uuid', 'session_hash', 'match_code', 'link_token_hash', 'user_id', 'agent_job_id',
        'status', 'ip', 'user_agent', 'expires_at', 'approved_at', 'consumed_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'approved_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected $hidden = ['session_hash', 'link_token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
