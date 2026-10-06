<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * One user's own settings for the package UI, as a small key/value bag.
 *
 * Only what a user chooses for themselves lives here — where their floating
 * launcher sits, for instance. Anything the host app decides stays in config.
 * Values are not trusted on the way out: LauncherSettings validates each one
 * as it would a config value.
 */
class UserPreference extends Model
{
    protected $table = 'digital_signature_user_preferences';

    protected $fillable = ['user_id', 'preferences'];

    protected $casts = [
        'preferences' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /** @return array<string, mixed> */
    public static function for(int|string|null $userId): array
    {
        if ($userId === null) {
            return [];
        }

        // The launcher reads this on every panel page. A host that upgraded
        // without running the migration yet gets the config defaults, not a
        // broken app.
        try {
            $preferences = static::query()->where('user_id', $userId)->value('preferences');
        } catch (QueryException) {
            return [];
        }

        // value() bypasses the cast.
        $decoded = is_string($preferences) ? json_decode($preferences, true) : $preferences;

        return is_array($decoded) ? $decoded : [];
    }
}
