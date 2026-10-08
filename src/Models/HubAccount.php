<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Client mode: a local user linked to a hub person (`sub`). */
class HubAccount extends Model
{
    protected $table = 'digital_signature_hub_accounts';

    protected $fillable = ['user_id', 'sub', 'claims', 'linked_at'];

    protected $casts = [
        'claims'    => 'array',
        'linked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public static function subFor(int $userId): ?string
    {
        return static::query()->where('user_id', $userId)->value('sub');
    }

    public static function userIdFor(string $sub): ?int
    {
        $id = static::query()->where('sub', $sub)->value('user_id');

        return $id === null ? null : (int) $id;
    }
}
