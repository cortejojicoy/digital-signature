<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignaturePosition extends Model
{
    protected $fillable = [
        'signature_id', 'page', 'x', 'y', 'width', 'height', 'label',
        // Which side of this stamp the provenance caption sits on. Null means
        // "whatever the application is configured for".
        'caption_position',
    ];

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }
}
