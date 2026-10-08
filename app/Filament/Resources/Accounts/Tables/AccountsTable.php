<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Actions\ConnectGoogleAction;
use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Actions\TestConnectionAction;
use App\Models\Account;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('calendars'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('username')
                    ->label('Utente')
                    ->state(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                        ? 'Credenziali illeggibili'
                        : ($record->credential('username') ?? $record->credential('email')))
                    ->color(fn (Account $record): ?string => $record->hasUnreadableCredentials() ? 'danger' : null)
                    ->tooltip(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                        ? 'Salvate con un\'altra APP_KEY: reinserisci la password o riconnetti l\'account.'
                        : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->tooltip(fn (Account $record): ?string => $record->status_message),
                TextColumn::make('calendars_count')
                    ->label('Calendari')
                    ->numeric(),
                TextColumn::make('last_checked_at')
                    ->label('Ultima verifica')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('mai')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    ConnectGoogleAction::make(),
                    TestConnectionAction::make(),
                    DiscoverCalendarsAction::make(),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun account')
            ->emptyStateDescription('Aggiungi un account iCloud, CalDAV o Google per iniziare.');
    }
}
