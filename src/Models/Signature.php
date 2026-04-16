<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Signature extends Model
{
    protected $fillable = [
        'uuid',
        'user_id', 'signable_type', 'signable_id',
        'image_path', 'image_hash',
        'document_hash',          // SHA-256 of source PDF before signing
        'signed_document_path',
        'signed_document_hash',   // SHA-256 of signed PDF after signing
        'machine_fingerprint',    // SHA-256 of userId|userAgent|ip|deviceFp at store time
        'source', 'status', 'certificate_fingerprint',
        'certificate_password', // Encrypted certificate password
        'pades_info', 'signed_at', 'revoked_at',
    ];

    protected $casts = [
        'pades_info' => 'array',
        'signed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function position(): HasOne
    {
        return $this->hasOne(SignaturePosition::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    public function getCertificatePassword(): ?string
    {
        if (! $this->certificate_password) {
            return null;
        }

        try {
            return decrypt($this->certificate_password);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function setCertificatePasswordAttribute(?string $value): void
    {
        $this->attributes['certificate_password'] = $value
            ? encrypt($value)
            : null;
    }
}
