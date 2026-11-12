<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\CalSync\Google\GoogleClientFactory;
use App\Enums\AccountType;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\GroupFields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AccountForm
{
    /**
     * The password is write-only: it is never filled into the form, an empty field on edit
     * keeps the stored one. Username and password are virtual fields mapped onto the
     * encrypted `credentials` attribute by the Create/Edit pages.
     */
    public static function configure(Schema $schema): Schema
    {
        $isIcloud = fn (Get $get): bool => AccountResource::typeFrom($get) === AccountType::IcloudCaldav;
        $isCalDav = fn (Get $get): bool => AccountResource::typeFrom($get)?->isCalDav() ?? true;
        $googleConfigured = app(GoogleClientFactory::class)->isConfigured();

        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('type')
                            ->label(__('Type'))
                            ->options(collect(AccountType::cases())->mapWithKeys(fn (AccountType $type) => [$type->value => $type->getLabel()])->all())
                            // Only new Google accounts need the OAuth client; existing ones stay editable.
                            ->disableOptionWhen(fn (string $value, string $operation): bool => $operation === 'create' && $value === AccountType::Google->value && ! $googleConfigured)
                            ->default(AccountType::IcloudCaldav->value)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live()
                            ->disabledOn('edit')
                            ->helperText(fn (Get $get, string $operation): ?string => match (true) {
                                $operation !== 'create' => null,
                                AccountResource::typeFrom($get) === AccountType::Google => __('After saving you will be taken to Google to authorise access to the calendar.'),
                                ! $googleConfigured => __('For Google accounts, first set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env (see docs/google-oauth.md).'),
                                default => null,
                            }),

                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('e.g. Personal iCloud'))
                            ->required()
                            ->maxLength(100),

                        GroupFields::select(),

                        TextInput::make('base_url')
                            ->label(__('CalDAV server URL'))
                            ->placeholder('https://cloud.example.com/remote.php/dav/')
                            ->url()
                            ->required()
                            ->maxLength(500)
                            ->visible(fn (Get $get): bool => AccountResource::typeFrom($get) === AccountType::CaldavGeneric)
                            ->helperText(__('The discovery tries this address, then /.well-known/caldav. Credentials are only sent to this host.'))
                            ->columnSpanFull(),

                        TextInput::make('username')
                            ->label(fn (Get $get): string => $isIcloud($get) ? 'Apple ID (email)' : 'Username')
                            ->visible($isCalDav)
                            ->required()
                            ->maxLength(255)
                            ->autocomplete('off'),

                        TextInput::make('password')
                            ->label(fn (Get $get): string => $isIcloud($get) ? __('App-specific password') : 'Password')
                            ->password()
                            ->revealable()
                            ->visible($isCalDav)
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255)
                            ->helperText(fn (Get $get, string $operation): string => match (true) {
                                $operation === 'edit' => __('Leave empty to keep the saved password. The saved password is never shown.'),
                                $isIcloud($get) => __('Not your Apple ID password: create one at appleid.apple.com → Sign-In and Security → App-Specific Passwords.'),
                                default => __('It is stored encrypted and never shown again.'),
                            }),
                    ]),
            ]);
    }
}
