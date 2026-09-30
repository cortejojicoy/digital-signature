<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One registered signing key: a browser profile, or a desktop agent install.
 *
 * The row holds only the public key. Possession of the private half is proven
 * afresh by the device (see DeviceRegistry), never assumed from this record.
 */
class SigningDevice extends Model
{
    protected $table = 'digital_signature_devices';

    protected $fillable = [
        'uuid', 'user_id', 'label',
        'public_key', 'key_fingerprint', 'algorithm',
        'kind', 'protection', 'user_presence', 'attested',
        'device_type', 'form_factor', 'platform', 'browser', 'model', 'user_agent',
        'hardware_id_hash', 'agent_version', 'session_public_key',
        'status', 'registered_ip', 'last_used_ip',
        'last_used_at', 'approved_at', 'revoked_at',
    ];

    protected $casts = [
        'user_presence' => 'boolean',
        'attested'      => 'boolean',
        'last_used_at'  => 'datetime',
        'approved_at'   => 'datetime',
        'revoked_at'    => 'datetime',
    ];

    protected $hidden = ['public_key', 'session_public_key', 'hardware_id_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /**
     * The label if the user has set one, otherwise what the device is.
     */
    public function displayName(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : $this->describe();
    }

    /**
     * "Chrome on Mac", "Safari on iPhone", "MacBook Pro". Built from what the
     * device reported, so it is a description, not a claim about the hardware.
     */
    public function describe(): string
    {
        if ($this->kind === 'agent' && $this->model) {
            return $this->model;
        }

        $browser = $this->browser ? preg_replace('/\s+\d+$/', '', $this->browser) : null;
        $platform = $this->platform ?: null;

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $platform !== null                      => $platform,
            $browser !== null                       => $browser,
            default                                 => 'Unknown device',
        };
    }

    /**
     * What protects the key, in words a signer understands.
     */
    public function protectionLabel(): string
    {
        return match ($this->protection) {
            'secure_enclave' => 'Secure Enclave',
            'tpm'            => 'TPM',
            'software'       => 'Software key',
            default          => 'Browser key',
        };
    }

    /**
     * SSH-style short form of the key fingerprint: `SHA256:q3Yx…Jk0`.
     */
    public function shortFingerprint(): string
    {
        $b64 = rtrim(base64_encode((string) hex2bin($this->key_fingerprint)), '=');

        return 'SHA256:'.substr($b64, 0, 8).'…'.substr($b64, -4);
    }

    /**
     * Heroicon name for the device's form.
     */
    public function icon(): string
    {
        return match ($this->device_type) {
            'mobile' => 'heroicon-o-device-phone-mobile',
            'tablet' => 'heroicon-o-device-tablet',
            default  => 'heroicon-o-computer-desktop',
        };
    }
}
