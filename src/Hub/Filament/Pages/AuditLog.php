<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Kukux\DigitalSignature\Hub\Identity\AuditPresenter;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: the global audit trail (plan 1.7). Each row answers when, what,
 * where (app, document, IP, browser), how (device, presence, proof purpose,
 * specimen) and with what outcome. Filter by person, app, event and date;
 * export what's filtered as CSV.
 */
class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'signature::hub.pages.admin-table';

    protected static ?string $slug = 'audit-log';

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public static function getNavigationLabel(): string
    {
        return 'Audit log';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Audit log';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SignatureAudit::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y H:i:s')->sortable(),
                TextColumn::make('event')->label('What')->formatStateUsing(fn (?string $state): string => AuditPresenter::what($state)),
                TextColumn::make('personnel_key')->label('Person')->fontFamily('mono')->toggleable()->searchable(),
                TextColumn::make('where')->label('Where')->state(fn (SignatureAudit $record): string => AuditPresenter::where($record) ?: '—')->wrap(),
                TextColumn::make('how')->label('How')->state(fn (SignatureAudit $record): string => AuditPresenter::how($record) ?: '—')->wrap(),
                TextColumn::make('outcome')->label('Outcome')->state(fn (SignatureAudit $record): string => AuditPresenter::outcome($record)),
            ])
            ->filters([
                Filter::make('person')
                    ->schema([TextInput::make('personnel_key')->label('Person (personnel key)')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['personnel_key'] ?? null)
                        ? $query->where('personnel_key', $data['personnel_key'])
                        : $query),
                SelectFilter::make('app')
                    ->label('App')
                    ->options(fn (): array => SignatureAudit::query()->whereNotNull('app')->distinct()->orderBy('app')->pluck('app', 'app')->all()),
                SelectFilter::make('event')
                    ->label('Event')
                    ->multiple()
                    ->options(AuditPresenter::events()),
                Filter::make('when')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', $until.' 23:59:59'))),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ]);
    }

    /** What the filters show, oldest first, as CSV, in the plan's when / what / where / how / outcome columns. */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery() ?? SignatureAudit::query();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['when', 'event', 'what', 'personnel_key', 'app', 'where', 'how', 'outcome', 'actor_type', 'ip', 'user_agent']);

            $query->chunkById(500, function ($audits) use ($out) {
                foreach ($audits as $audit) {
                    fputcsv($out, [
                        $audit->created_at?->toIso8601String(),
                        $audit->event,
                        AuditPresenter::what($audit->event),
                        $audit->personnel_key,
                        $audit->app,
                        AuditPresenter::where($audit),
                        AuditPresenter::how($audit),
                        AuditPresenter::outcome($audit),
                        $audit->actor_type,
                        $audit->ip,
                        $audit->user_agent,
                    ]);
                }
            });

            fclose($out);
        }, 'signature-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
