<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignaturePosition extends Model
{
    protected $fillable = ['signature_id', 'page', 'x', 'y', 'width', 'height', 'label'];

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }
}
