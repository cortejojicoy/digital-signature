<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One desktop-agent pairing attempt. See AgentPairingService.
 */
class AgentPairing extends Model
{
    protected $table = 'digital_signature_agent_pairings';

    protected $fillable = [
        'uuid', 'user_id', 'user_code_hash', 'nonce', 'poll_secret_hash',
        'status', 'claim', 'device_id', 'replaces_device_id', 'token_issued_at', 'expires_at',
    ];

    protected $casts = [
        'claim'           => 'array',
        'token_issued_at' => 'datetime',
        'expires_at'      => 'datetime',
    ];

    protected $hidden = ['user_code_hash', 'nonce', 'poll_secret_hash', 'claim'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(SigningDevice::class, 'device_id');
    }

    public function replacesDevice(): BelongsTo
    {
        return $this->belongsTo(SigningDevice::class, 'replaces_device_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
