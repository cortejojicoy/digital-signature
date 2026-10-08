<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One webhook in the hub's outbox (R6). */
class HubWebhook extends Model
{
    protected $table = 'digital_signature_hub_webhooks';

    protected $fillable = [
        'uuid', 'app_id', 'event', 'payload', 'attempts', 'next_attempt_at',
        'delivered_at', 'last_status', 'last_error',
    ];

    protected $casts = [
        'payload'         => 'array',
        'attempts'        => 'integer',
        'next_attempt_at' => 'datetime',
        'delivered_at'    => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(HubApp::class, 'app_id');
    }
}
