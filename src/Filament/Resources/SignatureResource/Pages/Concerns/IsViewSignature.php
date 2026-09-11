<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Everything the View Signature page does, minus the one thing that cannot be
 * shared: `Page::$view`, which is static on Filament v3 and an instance
 * property on v4/v5. Declaring the wrong kind is a hard PHP fatal at class
 * load, so the property lives in the V3/V4 subclasses and the behaviour lives
 * here.
 */
trait IsViewSignature
{
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
                ->url(fn (): ?string => $record->getTemporaryImageUrl(60))
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
