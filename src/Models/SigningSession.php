<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Owns one document through N signatures.
 *
 * The session exists so that a multi-signatory document has a single
 * identity: one frozen base PDF, one ordered list of requests, one status.
 * Without it, "the AR" would only be a loose set of digital_signatures rows
 * with no way to say whether it is finished.
 */
class SigningSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const MODE_SEQUENTIAL = 'sequential';

    public const MODE_PARALLEL = 'parallel';

    protected $table = 'digital_signing_sessions';

    protected $fillable = [
        'uuid',
        'signable_type', 'signable_id',
        'template_key',
        'base_document_path', 'base_document_hash',
        'current_document_path', 'current_document_hash',
        'status', 'sequence_mode', 'signing_mode',
        'opened_by',
        'completed_at', 'cancelled_at', 'expires_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'signing_session_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'signing_session_id')
            ->orderBy('sequence');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'opened_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForSignable(Builder $query, Model $signable): Builder
    {
        return $query
            ->where('signable_type', $signable->getMorphClass())
            ->where('signable_id', $signable->getKey());
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isSequential(): bool
    {
        return $this->sequence_mode === self::MODE_SEQUENTIAL;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The PDF a new signature should be applied to: the running document if
     * anyone has signed, otherwise the frozen base render.
     */
    public function documentToSign(): string
    {
        return $this->current_document_path ?: $this->base_document_path;
    }

    /**
     * The hash of the document the next signature will cover — becomes that
     * signature's `document_hash`, chaining it to its predecessor.
     */
    public function documentToSignHash(): ?string
    {
        return $this->current_document_hash ?: $this->base_document_hash;
    }

    /**
     * Requests that still need action before the session can complete.
     *
     * @return \Illuminate\Support\Collection<int, SignatureRequest>
     */
    public function outstandingRequests()
    {
        return $this->requests
            ->filter(fn (SignatureRequest $r) => $r->required && ! $r->isSigned());
    }
}
