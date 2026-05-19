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

        // Auto-select the user's primary signature when the picker mounts.
        // Each user is limited to a single active primary signature, so this
        // makes the modal a one-click confirm flow instead of forcing a manual
        // pick. If the form is re-opened with state already set (e.g. validation
        // error rebuild), we leave the existing selection alone.
        $this->default(function (): ?string {
            $userId = auth()->id();

            if (! $userId) {
                return null;
            }

            $id = Signature::primaryActiveFor((int) $userId)
                ->latest()
                ->value('id');

            return $id !== null ? (string) $id : null;
        });
    }

    public function getSignatures(): \Illuminate\Database\Eloquent\Collection
    {
        $userId = auth()->id();
        if (! $userId) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        // Only show primary (reusable) signatures — document-specific Signature
        // rows are signing events and aren't valid picks.
        return Signature::primaryActiveFor((int) $userId)
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
