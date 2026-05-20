<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Signature extends Model
{
    protected $table = 'digital_signatures';

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

    /**
     * Primary (reusable) signatures are not tied to a specific Signable —
     * they are the user's registered, document-agnostic signature image.
     * Document signing creates additional Signature rows with signable_id set;
     * those are not "primary" and are not counted against the one-per-user limit.
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->whereNull('signable_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'revoked');
    }

    public function scopePrimaryActiveFor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->primary()->active();
    }

    /**
     * "Active" means a registered, reusable primary signature ready to sign
     * documents. Only primary records (signable_id IS NULL) ever reach this
     * status; document-signing records go pending → signed → (optionally)
     * revoked instead.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
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

    public function isPrimary(): bool
    {
        return $this->signable_id === null;
    }

    /**
     * Build a short-lived URL the browser can use to render this signature's
     * image. Cloud drivers (s3, r2, gcs) sign their own URLs natively; local
     * and other no-temporary-URL drivers fall back to a signed route handled
     * by SignatureAssetController.
     *
     * Pass $ttlMinutes when you need a longer window than the configured
     * `signature.preview_url_ttl` default (e.g. for download links).
     */
    public function getTemporaryImageUrl(?int $ttlMinutes = null): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $ttl = $ttlMinutes ?? (int) config('signature.preview_url_ttl', 5);
        $expires = now()->addMinutes($ttl);

        try {
            return Storage::disk(config('signature.storage_disk'))
                ->temporaryUrl($this->image_path, $expires);
        } catch (\RuntimeException) {
            // Driver doesn't support native temporary URLs (e.g. local).
            // Fall through to the signed-route fallback below.
        }

        return URL::temporarySignedRoute(
            'signature.asset',
            $expires,
            ['signature' => $this->uuid],
        );
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
