<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;

/** A computer refused by the hub after a rejected claim (R8, R9). */
class HubBlock extends Model
{
    protected $table = 'digital_signature_hub_blocks';

    protected $fillable = ['hardware_id_hash', 'reason', 'until'];

    protected $casts = ['until' => 'datetime'];

    public static function isBlocked(?string $hardwareIdHash): bool
    {
        return $hardwareIdHash !== null && $hardwareIdHash !== ''
            && static::query()->where('hardware_id_hash', $hardwareIdHash)->where('until', '>', now())->exists();
    }
}
