<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Services\SignatureManager;

class ListSignatures extends ListRecords
{
    protected static string $resource = SignatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── Add Signature ────────────────────────────────────────────────────
            Action::make('createSignature')
                ->label('Add Signature')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('Add Signature')
                ->modalDescription('Draw your signature or upload an image.')
                ->modalWidth('xl')
                ->form([
                    SignaturePad::make('signature')
                        ->label('Your Signature')
                        ->canvasWidth(600)
                        ->canvasHeight(200)
                        ->required(),

                    TextInput::make('certificate_password')
                        ->label('Certificate Password')
                        ->password()
                        ->required()
                        ->hint('Protects your signing certificate')
                        ->hintIcon('heroicon-m-lock-closed')
                        ->placeholder('Enter your certificate password'),
                ])
                ->action(function (array $data): void {
                    $userId = auth()->id()
                        ?? throw new \RuntimeException('No authenticated user found.');

                    $source = str_contains($data['signature'] ?? '', 'data:image') ? 'upload' : 'draw';

                    app(SignatureManager::class)->store(
                        userId: $userId,
                        input: $data['signature'],
                        source: $source,
                        certificatePassword: $data['certificate_password'] ?? null,
                    );

                    Notification::make()
                        ->title('Signature added')
                        ->body('Your signature has been saved successfully.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
