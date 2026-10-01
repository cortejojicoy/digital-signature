<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paired agent's bearer token, stored as SHA-256 only.
 */
class AgentToken extends Model
{
    protected $table = 'digital_signature_agent_tokens';

    protected $fillable = ['device_id', 'token_hash', 'last_used_at', 'revoked_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SigningDevice::class, 'device_id');
    }
}
