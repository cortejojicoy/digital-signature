<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Approve this signing on your computer". See AgentJobService.
 */
class AgentJob extends Model
{
    protected $table = 'digital_signature_agent_jobs';

    protected $fillable = [
        'uuid', 'user_id', 'device_id', 'signature_id',
        'purpose', 'title', 'signable_type', 'signable_id',
        'payload_hash', 'nonce', 'link_token_hash',
        'status', 'reason',
        'requesting_app', 'meta',
        'claimed_at', 'completed_at', 'consumed_at', 'expires_at',
    ];

    protected $casts = [
        'meta'         => 'array',
        'claimed_at'   => 'datetime',
        'completed_at' => 'datetime',
        'consumed_at'  => 'datetime',
        'expires_at'   => 'datetime',
    ];

    protected $hidden = ['nonce', 'link_token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SigningDevice::class, 'device_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
