<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubDocument;

/**
 * Admin: the documents apps reported for PDF verification
 * (Hub\Documents\HubDocuments). One row per routed document: which app, what
 * it is, how far signing got, and how many versions the hub can recognise.
 * The hub holds fingerprints and signers only, never the PDFs, so there is
 * nothing here to open; "Verify a PDF" is the way to check a file.
 */
class Documents extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'signature::hub.pages.admin-table';

    protected static ?string $slug = 'documents';

    protected static ?int $navigationSort = 5;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-check';
    }

    public static function getNavigationLabel(): string
    {
        return 'Documents';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Reported documents';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(HubDocument::query()->with('app')->withCount('versions'))
            ->defaultSort('reported_at', 'desc')
            ->columns([
                TextColumn::make('reported_at')->label('Last reported')->dateTime('M j, Y H:i')->sortable(),
                TextColumn::make('app.name')->label('App'),
                TextColumn::make('title')->label('Document')->placeholder('Untitled')->searchable(),
                TextColumn::make('status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                    'complete' => 'success',
                    'open'     => 'warning',
                    default    => 'gray',
                }),
                TextColumn::make('signed')
                    ->label('Signed')
                    ->state(function (HubDocument $record): string {
                        $snapshot = $record->snapshot();
                        $signed = count(array_filter($snapshot->slots, fn (array $slot): bool => $slot['state'] === 'signed'));

                        return "{$signed} of {$snapshot->required}";
                    }),
                TextColumn::make('versions_count')->label('Versions')->numeric(),
                TextColumn::make('uuid')->label('Session')->fontFamily('mono')->limit(8)->toggleable(isToggledHiddenByDefault: true)->searchable(),
            ])
            ->filters([
                SelectFilter::make('app_id')
                    ->label('App')
                    ->options(fn (): array => HubApp::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(['open' => 'Open', 'complete' => 'Complete', 'cancelled' => 'Cancelled', 'expired' => 'Expired']),
            ])
            ->headerActions([
                Action::make('verify')
                    ->label('Verify a PDF')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn (): string => route('signature.verify.document'))
                    ->openUrlInNewTab(),
            ]);
    }
}
