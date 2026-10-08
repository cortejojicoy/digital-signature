<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support;

use Illuminate\Database\Eloquent\Model;

/** The hub app's Kafka-fed personnel table, as the identity tests see it. */
class HubTestPersonnel extends Model
{
    protected $table = 'hub_test_personnel';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
