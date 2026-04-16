<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Services\SignatureManager;

class CreateSignature extends CreateRecord
{
    protected static string $resource = SignatureResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['signature']) && filled($data['signature'])) {
            $data['source'] = str_contains($data['signature'], 'data:image') ? 'upload' : 'draw';
        } else {
            $data['source'] = 'draw';
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $signatureManager = app(SignatureManager::class);

        $userId = auth()->user()?->id
            ?? throw new \RuntimeException('No authenticated user found.');

        $signature = $signatureManager->store(
            userId: $userId,
            input: $data['signature'],
            source: $data['source'] ?? 'draw',
            certificatePassword: $data['certificate_password'] ?? null,
        );

        return $signature;
    }
}
