<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Actions\ConnectGoogleAction;
use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Actions\TestConnectionAction;
use App\Filament\Support\GroupFields;
use App\Models\Account;
use App\Support\DateFormat;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('group')->withCount('calendars'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                GroupFields::column(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('username')
                    ->label(__('User'))
                    ->state(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                        ? __('Unreadable credentials')
                        : ($record->credential('username') ?? $record->credential('email')))
                    ->color(fn (Account $record): ?string => $record->hasUnreadableCredentials() ? 'danger' : null)
                    ->tooltip(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                        ? __('Saved with another APP_KEY: enter the password again or reconnect the account.')
                        : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->tooltip(fn (Account $record): ?string => $record->status_message),
                TextColumn::make('calendars_count')
                    ->label(__('Calendars'))
                    ->numeric(),
                TextColumn::make('last_checked_at')
                    ->label(__('Last check'))
                    ->since()
                    ->dateTimeTooltip(DateFormat::dateTime())
                    ->placeholder(__('never'))
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                GroupFields::filter(),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                ActionGroup::make([
                    ConnectGoogleAction::make(),
                    TestConnectionAction::make(),
                    DiscoverCalendarsAction::make(),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GroupFields::bulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No accounts'))
            ->emptyStateDescription(__('Add an iCloud, CalDAV or Google account to get started.'));
    }
}
