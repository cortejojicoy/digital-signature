<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'signing_session_id', 'slot_key', 'sequence', 'parent_signature_id',
        'image_path', 'image_hash',
        'document_hash',          // SHA-256 of source PDF before signing
        'signed_document_path',
        'signed_document_hash',   // SHA-256 of signed PDF after signing
        'machine_fingerprint',    // SHA-256 of userId|userAgent|ip|deviceFp at store time
        'device_id',              // registered device: created on (primary) / used on (document)
        'source', 'status', 'certificate_fingerprint',
        'certificate_password', // Encrypted certificate password
        'pades_info', 'signed_at', 'revoked_at',
    ];

    protected $casts = [
        'pades_info' => 'array',
        'signed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The registered device this row came from. On a primary signature, the
     * device it was created on; on a document-signing row, the device it was
     * used on. Null when no verified device was present.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SigningDevice::class, 'device_id');
    }

    /**
     * The first place this signature is stamped.
     *
     * Kept because most signatures land in exactly one spot and every existing
     * caller reads it. `positions()` is the honest shape — see there.
     */
    public function position(): HasOne
    {
        return $this->hasOne(SignaturePosition::class);
    }

    /**
     * Every place this signature is stamped on the document.
     *
     * One signature, several appearances. A form routinely asks the same
     * person for the same signature more than once — once in the signature
     * block, again under a certificate, again on an acceptance clause — and
     * that is one act of signing, not three. So there is one Signature row,
     * one PKCS#7 block covering the whole document, and N rows here saying
     * where it is drawn. The cryptography does not count stamps.
     */
    public function positions(): HasMany
    {
        return $this->hasMany(SignaturePosition::class)->orderBy('id');
    }

    /**
     * The multi-signatory session this signature belongs to, when it was
     * produced as part of one. Null for standalone single-signer flows.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class, 'signing_session_id');
    }

    /**
     * The signature immediately before this one in the session's chain.
     * This signature's `document_hash` equals the parent's
     * `signed_document_hash`, which is what makes the chain verifiable.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_signature_id');
    }

    public function child(): HasOne
    {
        return $this->hasOne(self::class, 'parent_signature_id');
    }

    /**
     * True when this signature was applied under a delegation rather than
     * by its owner acting in the request.
     */
    public function wasAutoAffixed(): bool
    {
        return $this->source === 'auto';
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
     * The document-signing rows this primary signature produced.
     *
     * storeForDocument() copies the image rather than linking back, so the
     * image hash under the same owner is what ties a use to its source.
     */
    public function documentUses(): Builder
    {
        return static::query()
            ->where('user_id', $this->user_id)
            ->where('image_hash', $this->image_hash)
            ->whereNotNull('signable_id')
            ->whereKeyNot($this->getKey());
    }

    /**
     * One line per recent document this signature was used on:
     * "Contract · Chrome on Mac · Browser key · 2026-09-30 14:02".
     *
     * @return array<int, string>
     */
    public function documentUseSummaries(int $limit = 20): array
    {
        return $this->documentUses()
            ->with('device')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (self $use): string => implode(' · ', array_filter([
                $use->signableTitle(),
                $use->deviceSummary(),
                ($use->signed_at ?? $use->created_at)?->format('Y-m-d H:i'),
            ])))
            ->all();
    }

    /**
     * The signed record's title, falling back to "Type #id" — including when
     * the morph class no longer exists in the host app, which must not take
     * the page down with it.
     */
    public function signableTitle(): ?string
    {
        if ($this->signable_id === null) {
            return null;
        }

        try {
            $signable = $this->signable;
        } catch (\Throwable) {
            $signable = null;
        }

        return $signable && method_exists($signable, 'getSignableTitle')
            ? $signable->getSignableTitle()
            : class_basename((string) $this->signable_type).' #'.$this->signable_id;
    }

    /**
     * "Chrome on Mac · Browser key", or why there is no device: what the UI
     * shows in the Created on / Signed on slot.
     */
    public function deviceSummary(): string
    {
        if ($this->device) {
            return $this->device->displayName().' · '.$this->device->protectionLabel()
                .($this->device->isRevoked() ? ' (revoked)' : '');
        }

        return $this->source === 'auto' ? 'Applied automatically' : 'No verified device';
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
            ['digitalSignature' => $this->uuid],
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
