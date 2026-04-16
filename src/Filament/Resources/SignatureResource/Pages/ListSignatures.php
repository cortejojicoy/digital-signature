<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Kukux\DigitalSignature\Filament\Actions\SignDocumentAction;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;

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
                ->url(static::getResource()::getUrl('create')),

            // ── Sign Document ─────────────────────────────────────────────────
            // Opens the SignDocumentAction modal from within the resource.
            // Records created here are standalone (not attached to a signable).
            SignDocumentAction::make()
                ->label('Sign Document')
                ->icon('heroicon-o-pencil-square'),
        ];
    }
}
