<?php

namespace Kukux\DigitalSignature\Filament\Fields;

use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\Signature;

class SignaturePickerField extends Field
{
    protected string $view = 'signature::components.signature-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrateStateUsing(fn (mixed $state): mixed => $state);
    }

    public function getSignatures(): \Illuminate\Database\Eloquent\Collection
    {
        $userId = auth()->id();
        if (! $userId) {
            return collect();
        }

        return Signature::where('user_id', $userId)
            ->where('status', '!=', 'revoked')
            ->latest()
            ->get();
    }

    public function getSignatureImageUrl(Signature $signature): string
    {
        try {
            return Storage::disk(config('signature.storage_disk'))->url($signature->image_path);
        } catch (\Exception) {
            return '';
        }
    }
}
