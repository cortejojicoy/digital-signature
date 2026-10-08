<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a hub account is (hub mode). Created with the provisional account when
 * someone clicks "Pair this computer"; linked to a personnel record when they
 * identify themselves. `personnel_key` is the `sub` apps see.
 */
class Identity extends Model
{
    public const UNIDENTIFIED = 'unidentified';

    public const PENDING = 'pending_verification';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const RETIRED = 'retired';

    public const SEPARATED = 'separated';

    protected $table = 'digital_signature_identities';

    protected $fillable = [
        'user_id', 'personnel_key', 'status', 'session_hash', 'search_count',
        'claimed_at', 'verified_by', 'verified_at', 'retired_reason',
    ];

    protected $casts = [
        'claimed_at'   => 'datetime',
        'verified_at'  => 'datetime',
        'search_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function isIdentified(): bool
    {
        return in_array($this->status, [self::PENDING, self::VERIFIED], true);
    }

    /** Still allowed into the person panel at all. */
    public function isUsable(): bool
    {
        return ! in_array($this->status, [self::RETIRED, self::SEPARATED, self::REJECTED], true);
    }

    public static function forUser(int $userId): ?self
    {
        return static::query()->where('user_id', $userId)->first();
    }

    /** The current (non-retired) account linked to a person, if any. */
    public static function current(string $personnelKey): ?self
    {
        return static::query()
            ->where('personnel_key', $personnelKey)
            ->whereIn('status', [self::PENDING, self::VERIFIED])
            ->latest('id')
            ->first();
    }
}
