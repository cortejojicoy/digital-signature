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
        $disk = Storage::disk(config('signature.storage_disk'));

        if (! $signature->image_path || ! $disk->exists($signature->image_path)) {
            return '';
        }

        try {
            return $disk->temporaryUrl(
                $signature->image_path,
                now()->addMinutes(config('signature.preview_url_ttl', 5)),
            );
        } catch (\Throwable) {
            try {
                return $disk->url($signature->image_path);
            } catch (\Throwable) {
                return '';
            }
        }
    }
}
