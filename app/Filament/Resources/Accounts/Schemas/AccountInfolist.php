<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Models\Account;
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
                            ->label('Tipo')
                            ->badge(),
                        TextEntry::make('group.name')
                            ->label('Gruppo')
                            ->badge()
                            ->color('gray')
                            ->placeholder('Nessun gruppo'),
                        TextEntry::make('username')
                            ->label('Utente')
                            ->state(fn (Account $record): ?string => $record->hasUnreadableCredentials()
                                ? 'Credenziali illeggibili: salvate con un\'altra APP_KEY. Reinserisci la password o riconnetti l\'account.'
                                : ($record->credential('username') ?? $record->credential('email')))
                            ->color(fn (Account $record): ?string => $record->hasUnreadableCredentials() ? 'danger' : null)
                            ->placeholder('—'),
                        TextEntry::make('base_url')
                            ->label('Server')
                            ->visible(fn (Account $record): bool => $record->type === AccountType::CaldavGeneric),
                        TextEntry::make('status')
                            ->label('Stato')
                            ->badge(),
                        TextEntry::make('last_checked_at')
                            ->label('Ultima verifica')
                            ->since()
                            ->dateTimeTooltip('d/m/Y H:i')
                            ->placeholder('mai'),
                        TextEntry::make('status_message')
                            ->label('Dettaglio')
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
                            ->placeholder('non ancora scoperto')
                            ->copyable(),
                        TextEntry::make('calendar_home_url')
                            ->label('Calendar home')
                            ->placeholder('non ancora scoperta')
                            ->copyable(),
                    ]),
            ]);
    }
}
