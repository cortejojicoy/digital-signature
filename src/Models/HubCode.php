<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time authorization code (authorization code + PKCE). */
class HubCode extends Model
{
    protected $table = 'digital_signature_hub_codes';

    protected $fillable = ['code_hash', 'app_id', 'user_id', 'redirect_uri', 'code_challenge', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }
}
