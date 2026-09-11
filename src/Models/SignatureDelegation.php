<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A standing, scoped, revocable consent: "apply my signature when I am
 * tagged as <role> on a <template>, until <date>".
 *
 * This model is the only thing that makes auto-affix legitimate. Everything
 * about it is deliberately narrow — it names one signature image, one
 * template, usually one role, and always an expiry. A grant that cannot say
 * exactly what it authorises is not consent.
 */
class SignatureDelegation extends Model
{
    protected $table = 'digital_signature_delegations';

    protected $fillable = [
        'uuid',
        'user_id', 'signature_id',
        'template_key', 'role',
        'signable_type', 'signable_id',
        'max_uses', 'uses',
        'expires_at', 'revoked_at',
        'granted_ip', 'granted_user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'max_uses'   => 'integer',
        'uses'       => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $delegation) {
            $delegation->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Grants that are currently capable of authorising an auto-affix —
     * not revoked, not expired, not used up.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q
                ->whereNull('max_uses')
                ->orWhereColumn('uses', '<', 'max_uses'));
    }

    public function scopeFor(Builder $query, int $userId, string $templateKey, string $role): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('template_key', $templateKey)
            // A null role means "every role on this template".
            ->where(fn (Builder $q) => $q->whereNull('role')->orWhere('role', $role));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked()
            && ! $this->hasExpired()
            && ! $this->isExhausted();
    }

    /**
     * True when this grant covers the given record. A grant with no
     * signable scope covers every record of its template.
     */
    public function covers(Model $signable): bool
    {
        if ($this->signable_type === null) {
            return true;
        }

        return $this->signable_type === $signable->getMorphClass()
            && (string) $this->signable_id === (string) $signable->getKey();
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function recordUse(): void
    {
        $this->increment('uses');
    }

    /**
     * Guard enforcing non-negotiable #2 from the design: a grant may only be
     * created by the grantor, acting in their own authenticated session.
     * An administrator cannot consent on somebody else's behalf.
     *
     * @throws \Kukux\DigitalSignature\Exceptions\DelegationNotPermittedException
     */
    public static function assertGrantable(int $grantorId, ?int $actorId): void
    {
        if ($actorId === null || $actorId !== $grantorId) {
            throw new \Kukux\DigitalSignature\Exceptions\DelegationNotPermittedException(
                'A signature delegation can only be created by its grantor, in their own session. '
                .'An administrator cannot grant auto-signing consent on another user\'s behalf.'
            );
        }
    }
}
