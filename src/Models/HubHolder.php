<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An app that holds (or may hold) a mirror of a person's signature. */
class HubHolder extends Model
{
    protected $table = 'digital_signature_hub_holders';

    protected $fillable = ['app_id', 'personnel_key', 'linked_at', 'last_pulled_at'];

    protected $casts = [
        'linked_at'      => 'datetime',
        'last_pulled_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }

    public static function link(int $appId, string $personnelKey): self
    {
        $holder = static::query()->firstOrNew(['app_id' => $appId, 'personnel_key' => $personnelKey]);
        $holder->linked_at ??= now();
        $holder->save();

        return $holder;
    }
}
