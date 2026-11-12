<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\DateFormat;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('type')
                            ->label(__('Type'))
                            ->badge(),
                        TextEntry::make('group.name')
                            ->label(__('Group'))
                            ->badge()
                            ->color('gray')
                            ->placeholder(__('No group')),
                        TextEntry::make('username')
                            ->label(__('User'))
                            ->state(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                                ? __('Unreadable credentials: saved with another APP_KEY. Enter the password again or reconnect the account.')
                                : ($record->credential('username') ?? $record->credential('email')))
                            ->color(fn (Account $record): ?string => $record->hasUnreadableCredentials() ? 'danger' : null)
                            ->placeholder('—'),
                        TextEntry::make('base_url')
                            ->label('Server')
                            ->visible(fn (Account $record): bool => $record->type === AccountType::CaldavGeneric),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),
                        TextEntry::make('last_checked_at')
                            ->label(__('Last check'))
                            ->since()
                            ->dateTimeTooltip(DateFormat::dateTime())
                            ->placeholder(__('never')),
                        TextEntry::make('status_message')
                            ->label(__('Status details'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Discovery CalDAV')
                    ->visible(fn (Account $record): bool => $record->type->isCalDav())
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('principal_url')
                            ->label('Principal')
                            ->placeholder(__('not discovered yet'))
                            ->copyable(),
                        TextEntry::make('calendar_home_url')
                            ->label('Calendar home')
                            ->placeholder(__('not discovered yet'))
                            ->copyable(),
                    ]),
            ]);
    }
}
