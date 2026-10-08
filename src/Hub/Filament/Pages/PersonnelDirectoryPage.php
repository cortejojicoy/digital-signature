<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Actions\Action;
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
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Hub\Identity\EloquentPersonnelDirectory;
use Kukux\DigitalSignature\Hub\Identity\PersonnelStatus;

/**
 * Admin: everyone in the HR registry (plan 1.7), with where they stand at
 * the hub. Built over the configured personnel model
 * (signature.hub.personnel.model), the same one the directory searches.
 */
class PersonnelDirectoryPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'signature::hub.pages.admin-table';

    protected static ?string $slug = 'personnel';

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-users';
    }

    public static function getNavigationLabel(): string
    {
        return 'Personnel';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Personnel';
    }

    public function table(Table $table): Table
    {
        $directory = app(EloquentPersonnelDirectory::class);
        $key = $directory->column('key');
        $unit = $directory->column('unit');
        $keyOf = fn (Model $record): string => (string) $record->getAttribute($key);

        return $table
            ->query($directory->query())
            ->defaultSort($directory->column('name'))
            ->columns([
                TextColumn::make($directory->column('name'))->label('Name')->searchable()->sortable(),
                TextColumn::make($directory->column('emp_no'))->label('Emp. no.')->searchable()->fontFamily('mono'),
                TextColumn::make($unit)->label('Unit')->sortable()->toggleable(),
                TextColumn::make('hub_status')
                    ->label('Identity')
                    ->badge()
                    ->state(fn (Model $record): string => PersonnelStatus::label(PersonnelStatus::of($keyOf($record))))
                    ->color(fn (string $state): string => match ($state) {
                        'Verified'  => 'success',
                        'Pending'   => 'warning',
                        'Separated' => 'danger',
                        default     => 'gray',
                    }),
                TextColumn::make('hub_computer')
                    ->label('Paired computer')
                    ->state(fn (Model $record): string => PersonnelStatus::computer($keyOf($record))?->displayName() ?? '—'),
                TextColumn::make('hub_last_use')
                    ->label('Last signature use')
                    ->state(fn (Model $record): string => PersonnelStatus::lastUse($keyOf($record))?->diffForHumans() ?? 'never'),
            ])
            ->filters([
                SelectFilter::make('unit')
                    ->label('Unit')
                    ->options(fn (): array => $directory->query()->whereNotNull($unit)->distinct()->orderBy($unit)->pluck($unit, $unit)->all())
                    ->attribute($unit),
                SelectFilter::make('hub_status')
                    ->label('Identity')
                    ->options(PersonnelStatus::LABELS)
                    ->query(function (Builder $query, array $data) use ($key): Builder {
                        return match ($data['value'] ?? null) {
                            null, ''                    => $query,
                            PersonnelStatus::UNCLAIMED  => $query->whereNotIn($key, PersonnelStatus::keysIn(PersonnelStatus::UNCLAIMED)),
                            default                     => $query->whereIn($key, PersonnelStatus::keysIn($data['value'])),
                        };
                    }),
                Filter::make('has_computer')
                    ->label('Has a paired computer')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn($key, PersonnelStatus::keysWithComputer())),
                Filter::make('inactive')
                    ->schema([TextInput::make('days')->label('Signature unused for (days)')->numeric()->minValue(1)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['days'] ?? null)
                        ? $query->whereNotIn($key, PersonnelStatus::keysUsedSince(now()->subDays((int) $data['days'])))
                        : $query),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (Model $record): string => PersonnelProfile::getUrl(['key' => $keyOf($record)])),
            ])
            ->recordUrl(fn (Model $record): string => PersonnelProfile::getUrl(['key' => $keyOf($record)]));
    }
}
