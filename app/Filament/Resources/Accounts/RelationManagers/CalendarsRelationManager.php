<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Pages\CalendarPreview;
use App\Models\Calendar;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * Read-only list: calendars come from the discovery, never edited by hand.
 */
class CalendarsRelationManager extends RelationManager
{
    protected static string $relationship = 'calendars';

    protected static ?string $title = 'Calendari';

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
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('can_write')
                    ->label('Scrittura')
                    ->boolean()
                    ->tooltip(fn (Calendar $record): string => $record->can_write
                        ? 'Utilizzabile come destinazione'
                        : 'Sola lettura: utilizzabile solo come sorgente'),
                TextColumn::make('timezone')
                    ->label('Timezone')
                    ->placeholder('—'),
                TextColumn::make('missing_since')
                    ->label('Stato')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (): string => 'Non più trovato')
                    ->tooltip(fn (Calendar $record): ?string => $record->missing_since?->timezone(config('calsync.timezone'))->format('\d\a\l d/m/Y H:i'))
                    ->placeholder('Attivo'),
                TextColumn::make('remote_id')
                    ->label('URL / ID remoto')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->headerActions([
                DiscoverCalendarsAction::make(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Anteprima eventi')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Calendar $record): string => CalendarPreview::getUrl(['calendar' => $record->id])),
            ])
            ->emptyStateHeading('Nessun calendario')
            ->emptyStateDescription('Usa "Scopri calendari" per leggerli dal server.');
    }
}
