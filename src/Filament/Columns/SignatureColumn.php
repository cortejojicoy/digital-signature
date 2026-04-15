<?php

namespace Kukux\DigitalSignature\Filament\Columns;

use Kukux\DigitalSignature\Models\Signature;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SignatureColumn extends Column
{
    protected string $view = 'signature::components.signature-preview';

    protected int $thumbWidth  = 80;
    protected int $thumbHeight = 32;

    public function thumbSize(int $w, int $h): static
    {
        $this->thumbWidth  = $w;
        $this->thumbHeight = $h;
        return $this;
    }

    public function getThumbWidth(): int  { return $this->thumbWidth; }
    public function getThumbHeight(): int { return $this->thumbHeight; }

    /**
     * Return the temporary URL for the signature image of the related record.
     * Assumes the table row model has a `latestSignature()` or a direct
     * `signature` relationship that includes `image_path`.
     */
    public function getImageUrl(Model $record): ?string
    {
        $sig = method_exists($record, 'latestSignature')
            ? $record->latestSignature()
            : ($record->signature ?? null);

        if (!$sig || !$sig->image_path) return null;

        $disk = Storage::disk(config('signature.storage_disk'));

        return method_exists($disk, 'temporaryUrl')
            ? $disk->temporaryUrl($sig->image_path, now()->addMinutes(5))
            : $disk->url($sig->image_path);
    }

    public function getStatusBadge(Model $record): ?string
    {
        $sig = method_exists($record, 'latestSignature')
            ? $record->latestSignature()
            : ($record->signature ?? null);

        return $sig?->status;
    }
}