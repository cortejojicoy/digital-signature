<?php

namespace Kukux\DigitalSignature\Traits;

use Kukux\DigitalSignature\Models\Signature;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSignatures
{
    public function signatures(): MorphMany
    {
        return $this->morphMany(Signature::class, 'signable');
    }

    public function pendingSignatures(): MorphMany
    {
        return $this->signatures()->where('status', 'pending');
    }

    public function isSigned(): bool
    {
        return $this->signatures()->where('status', 'signed')->exists();
    }

    public function latestSignature(): ?Signature
    {
        return $this->signatures()->latest('id')->first();
    }
}