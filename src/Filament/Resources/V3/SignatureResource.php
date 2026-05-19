<?php

namespace Kukux\DigitalSignature\Filament\Resources\V3;

use Filament\Facades\Filament;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Kukux\DigitalSignature\Filament\Fields\SignaturePad;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\SignaturePlugin;

/**
 * Filament v3 implementation.
 *
 * Differences from V4:
 *  - Resource::form() receives \Filament\Forms\Form (not Schemas\Schema).
 *  - Resource::infolist() receives \Filament\Infolists\Infolist.
 *  - Section lives in Filament\Forms\Components\Section (forms) and
 *    Filament\Infolists\Components\Section (infolists) — not Schemas.
 *  - Schemas use ->schema([...]) instead of ->components([...]).
 *  - Table actions live in Filament\Tables\Actions\* and are attached via
 *    ->actions([...]) / ->bulkActions([...]) — not ->recordActions() / ->toolbarActions().
 */
class SignatureResource extends Resource
{
    protected static ?string $model = Signature::class;

    protected static ?string $recordTitleAttribute = 'uuid';

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public static function getNavigationIcon(): ?string
    {
        return static::plugin()?->getNavigationIcon()
            ?? config('signature.resource.navigation_icon', 'heroicon-o-pencil-square');
    }

    public static function getNavigationGroup(): ?string
    {
        return static::plugin()?->getNavigationGroup()
            ?? config('signature.resource.navigation_group');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = static::plugin()?->getNavigationSort()
            ?? config('signature.resource.navigation_sort');

        return $sort !== null ? (int) $sort : null;
    }

    public static function getNavigationLabel(): string
    {
        return static::plugin()?->getNavigationLabel()
            ?? config('signature.resource.navigation_label', 'Signatures');
    }

    public static function getModelLabel(): string
    {
        return 'Signature';
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
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
        ]);
    }

    // -------------------------------------------------------------------------
    // Infolist
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(3)
            ->schema([

                // ── Signature image (spans left 2 columns) ────────────────────
                InfolistSection::make()
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
                InfolistSection::make('Signer')
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
                InfolistSection::make('Security Metadata')
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
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([

                ImageColumn::make('image_path')
                    ->label('Signature')
                    ->disk(config('signature.storage_disk'))
                    ->height(32)
                    ->width(90)
                    ->extraImgAttributes([
                        'class' => 'object-contain dark:invert dark:brightness-90',
                        'style' => 'background:white;border-radius:4px;padding:2px;',
                    ]),

                TextColumn::make('user.name')
                    ->label('Signer')
                    ->searchable()
                    ->description(fn (Signature $record): string => $record->user?->email ?? ''),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'signed' => 'success',
                        'revoked' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('source')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'draw' ? 'info' : 'primary'),

                TextColumn::make('signed_at')
                    ->label('Signed')
                    ->dateTime()
                    ->placeholder('Pending')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'signed' => 'Signed',
                        'revoked' => 'Revoked',
                    ]),

                SelectFilter::make('source')
                    ->label('Capture Method')
                    ->options([
                        'draw' => 'Draw',
                        'upload' => 'Upload',
                    ]),
            ])
            ->actions([
                ViewAction::make(),

                TableAction::make('revoke')
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
                //
            ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSignatures::route('/'),
            'create' => Pages\CreateSignature::route('/create'),
            'view'   => Pages\ViewSignature::route('/{record}'),
        ];
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function plugin(): ?SignaturePlugin
    {
        try {
            /** @var SignaturePlugin */
            return Filament::getPlugin('signature');
        } catch (\LogicException) {
            return null;
        }
    }
}
