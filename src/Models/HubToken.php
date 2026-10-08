<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An access token issued by the hub: an app's own, or a person's (user_id set). */
class HubToken extends Model
{
    protected $table = 'digital_signature_hub_tokens';

    protected $fillable = ['token_hash', 'app_id', 'user_id', 'scopes', 'expires_at', 'revoked_at'];

    protected $casts = [
        'scopes'     => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
