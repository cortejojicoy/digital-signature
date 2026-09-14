<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Kukux\DigitalSignature\Filament\Concerns\RegistersSignatures;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;

class ListSignatures extends ListRecords
{
    use RegistersSignatures;

    protected static string $resource = SignatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── Add Signature ────────────────────────────────────────────────────
            //
            // The body of this action lives in RegistersSignatures, shared with
            // the launcher drawer's library tab. Both surfaces create a signing
            // credential, so the single-primary rule and the race it can lose
            // are decided in one place rather than two.
            Action::make('createSignature')
                ->label('Add Signature')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => $this->canRegisterSignature())
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
                ->action(fn (array $data) => $this->registerSignature(
                    $data['signature'] ?? null,
                    $data['certificate_password'] ?? null,
                )),
        ];
    }
}
