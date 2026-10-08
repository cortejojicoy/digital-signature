<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An app registered with the hub (hub mode): performance, amp, sims… */
class HubApp extends Model
{
    protected $table = 'digital_signature_hub_apps';

    protected $fillable = [
        'client_id', 'name', 'secret_hash', 'webhook_url', 'webhook_secret',
        'redirect_uris', 'scopes', 'mirror_prefix', 'active',
    ];

    protected $casts = [
        'webhook_secret' => 'encrypted',
        'redirect_uris'  => 'array',
        'scopes'         => 'array',
        'active'         => 'boolean',
    ];

    protected $hidden = ['secret_hash', 'webhook_secret'];

    public function holders(): HasMany
    {
        return $this->hasMany(HubHolder::class, 'app_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(HubWebhook::class, 'app_id');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) ($this->scopes ?? []), true);
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, (array) ($this->redirect_uris ?? []), true);
    }

    public function checkSecret(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }
}
