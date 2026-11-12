<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Pages\CalendarPreview;
use App\Models\Calendar;
use App\Support\DateFormat;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Read-only list: calendars come from the discovery, never edited by hand.
 */
class CalendarsRelationManager extends RelationManager
{
    protected static string $relationship = 'calendars';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Calendars');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    #[On(DiscoverCalendarsAction::REFRESH_EVENT)]
    public function refreshCalendars(): void
    {
        // Re-render after a discovery triggered from the page header.
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ColorColumn::make('color')
                    ->label(''),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('can_write')
                    ->label(__('Write'))
                    ->boolean()
                    ->tooltip(fn (Calendar $record): string => $record->can_write
                        ? __('Can be used as a target')
                        : __('Read-only: can only be used as a source')),
                TextColumn::make('timezone')
                    ->label('Timezone')
                    ->placeholder('—'),
                TextColumn::make('missing_since')
                    ->label(__('Status'))
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (): string => __('No longer found'))
                    ->tooltip(fn (Calendar $record): ?string => $record->missing_since
                        ? __('since :date', ['date' => $record->missing_since->timezone(config('calsync.timezone'))->format(DateFormat::dateTime())])
                        : null)
                    ->placeholder(__('Active')),
                TextColumn::make('remote_id')
                    ->label(__('Remote URL / ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->headerActions([
                DiscoverCalendarsAction::make(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Event preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Calendar $record): string => CalendarPreview::getUrl(['calendar' => $record->id])),
            ])
            ->emptyStateHeading(__('No calendars'))
            ->emptyStateDescription(__('Use "Discover calendars" to read them from the server.'));
    }
}
