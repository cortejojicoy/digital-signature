<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host app's people table, kept apart from logins: the Personnel/Employee
 * shape that SignatoryUserMapper exists for. `user_id` may be null (someone
 * on the org chart with no account).
 */
class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function getFullNameAttribute(): string
    {
        return (string) $this->getAttribute('name');
    }
}
