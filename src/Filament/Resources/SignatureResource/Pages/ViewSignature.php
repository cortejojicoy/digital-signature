<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Models\Signature;

class ViewSignature extends ViewRecord
{
    protected static string $resource = SignatureResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Signature $record */
        $record = $this->getRecord();

        return [
            // ── Sign Document (sign a new document from this view) ────────────
            SignDocumentAction::make()
                ->label('Sign Document')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),

            // ── Download ──────────────────────────────────────────────────────
            Action::make('download')
                ->label('Download Image')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) $record->image_path)
                ->url(function () use ($record): string {
                    $disk = Storage::disk(config('signature.storage_disk'));
                    return method_exists($disk, 'temporaryUrl')
                        ? $disk->temporaryUrl($record->image_path, now()->addMinutes(5))
                        : $disk->url($record->image_path);
                })
                ->openUrlInNewTab(),

            // ── Revoke ────────────────────────────────────────────────────────
            Action::make('revoke')
                ->label('Revoke Signature')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Revoke Signature')
                ->modalDescription('This signature will be permanently revoked and can no longer be used. This cannot be undone.')
                ->visible(fn (): bool => ! $record->isRevoked())
                ->action(function () use ($record): void {
                    app(\Kukux\DigitalSignature\Services\SignatureManager::class)->revoke($record);
                    $this->refreshFormData(['status', 'revoked_at']);
                    Notification::make()
                        ->title('Signature revoked')
                        ->success()
                        ->send();
                }),
        ];
    }
}
