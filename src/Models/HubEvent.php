<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;

/** Client mode: a webhook already handled, so a redelivery is a no-op. */
class HubEvent extends Model
{
    public $timestamps = false;

    protected $table = 'digital_signature_hub_events';

    protected $fillable = ['event_id', 'event', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];
}
