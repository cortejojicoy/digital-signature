<?php

namespace Kukux\DigitalSignature\Filament\Resources;

use Filament\Facades\Filament;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\SignaturePlugin;

class SignatureResource extends Resource
{
    protected static ?string $model = Signature::class;

    protected static ?string $recordTitleAttribute = 'uuid';

    // -------------------------------------------------------------------------
    // Navigation — reads from the plugin instance so runtime overrides take
    // effect without needing to extend/republish this class.
    // -------------------------------------------------------------------------

    public static function getNavigationIcon(): ?string
    {
        return static::plugin()->getNavigationIcon();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::plugin()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::plugin()->getNavigationSort();
    }

    public static function getNavigationLabel(): string
    {
        return static::plugin()->getNavigationLabel();
    }

    public static function getModelLabel(): string
    {
        return 'Signature';
    }

    public static function getPluralModelLabel(): string
    {
        return static::plugin()->getNavigationLabel();
    }

    // -------------------------------------------------------------------------
    // Form (only needed for create/edit — we only support view)
    // -------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    // -------------------------------------------------------------------------
    // Infolist — used by the View page
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(3)
            ->schema([

                // ── Signature image (spans left 2 columns) ────────────────────
                Section::make()
                    ->columnSpan(2)
                    ->schema([
                        ImageEntry::make('image_path')
                            ->label('Signature Image')
                            ->disk(config('signature.storage_disk'))
                            ->height(160)
                            ->extraImgAttributes([
                                'class' => 'object-contain mx-auto dark:invert dark:brightness-90',
                                'style' => 'background:white;border-radius:8px;padding:10px;max-width:480px;',
                            ]),
                    ]),

                // ── Signer + status (right column) ───────────────────────────
                Section::make('Signer')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Name')
                            ->placeholder('—'),

                        TextEntry::make('user.email')
                            ->label('Email')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'signed' => 'success',
                                'revoked' => 'danger',
                                default => 'warning',
                            }),

                        TextEntry::make('source')
                            ->label('Capture Method')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state))
                            ->color(fn (string $state): string => $state === 'draw' ? 'info' : 'primary'),

                        TextEntry::make('signed_at')
                            ->label('Signed At')
                            ->dateTime()
                            ->placeholder('Not yet signed'),

                        TextEntry::make('created_at')
                            ->label('Registered')
                            ->dateTime(),
                    ]),

                // ── Security metadata (collapsed) ─────────────────────────────
                Section::make('Security Metadata')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('uuid')
                            ->label('Record ID')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),

                        TextEntry::make('image_hash')
                            ->label('Image Hash (SHA-256)')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),

                        TextEntry::make('machine_fingerprint')
                            ->label('Device Fingerprint')
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 20).'…' : '—')
                            ->copyable(),

                        TextEntry::make('certificate_fingerprint')
                            ->label('Certificate Fingerprint')
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 20).'…' : '—')
                            ->placeholder('—')
                            ->copyable(),
                    ]),

            ]);
    }

    // -------------------------------------------------------------------------
    // Table — used by the List page
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([

                // Signature thumbnail
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Signature')
                    ->disk(config('signature.storage_disk'))
                    ->height(32)
                    ->width(90)
                    ->extraImgAttributes([
                        'class' => 'object-contain dark:invert dark:brightness-90',
                        'style' => 'background:white;border-radius:4px;padding:2px;',
                    ]),

                // Signer
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Signer')
                    ->searchable()
                    ->description(fn (Signature $record): string => $record->user?->email ?? ''),

                // Status badge
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'signed' => 'success',
                        'revoked' => 'danger',
                        default => 'warning',
                    }),

                // Capture method
                Tables\Columns\TextColumn::make('source')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'draw' ? 'info' : 'primary'),

                // Dates
                Tables\Columns\TextColumn::make('signed_at')
                    ->label('Signed')
                    ->dateTime()
                    ->placeholder('Pending')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'signed' => 'Signed',
                        'revoked' => 'Revoked',
                    ]),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Capture Method')
                    ->options([
                        'draw' => 'Draw',
                        'upload' => 'Upload',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke Signature')
                    ->modalDescription('This signature will be marked as revoked and can no longer be used to sign documents. This cannot be undone.')
                    ->visible(fn (Signature $record): bool => ! $record->isRevoked())
                    ->action(function (Signature $record): void {
                        app(SignatureManager::class)->revoke($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSignatures::route('/'),
            'view' => Pages\ViewSignature::route('/{record}'),
        ];
    }

    // -------------------------------------------------------------------------
    // Query — scope to current user unless they're an admin
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    // -------------------------------------------------------------------------
    // Internal helper
    // -------------------------------------------------------------------------

    private static function plugin(): SignaturePlugin
    {
        /** @var SignaturePlugin */
        return Filament::getPlugin('signature');
    }
}
