<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\CalSync\Google\GoogleClientFactory;
use App\Enums\AccountType;
use App\Filament\Resources\Accounts\AccountResource;
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
                            ->label('Tipo')
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
                                AccountResource::typeFrom($get) === AccountType::Google => 'Dopo il salvataggio verrai portato su Google per autorizzare l\'accesso al calendario.',
                                ! $googleConfigured => 'Per gli account Google imposta prima GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET nel .env (vedi docs/google-oauth.md).',
                                default => null,
                            }),

                        TextInput::make('name')
                            ->label('Nome')
                            ->placeholder('es. iCloud personale')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('base_url')
                            ->label('URL del server CalDAV')
                            ->placeholder('https://cloud.example.com/remote.php/dav/')
                            ->url()
                            ->required()
                            ->maxLength(500)
                            ->visible(fn (Get $get): bool => AccountResource::typeFrom($get) === AccountType::CaldavGeneric)
                            ->helperText('La discovery prova questo indirizzo e poi /.well-known/caldav. Le credenziali vengono inviate solo a questo host.')
                            ->columnSpanFull(),

                        TextInput::make('username')
                            ->label(fn (Get $get): string => $isIcloud($get) ? 'Apple ID (email)' : 'Username')
                            ->visible($isCalDav)
                            ->required()
                            ->maxLength(255)
                            ->autocomplete('off'),

                        TextInput::make('password')
                            ->label(fn (Get $get): string => $isIcloud($get) ? 'Password per app' : 'Password')
                            ->password()
                            ->revealable()
                            ->visible($isCalDav)
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255)
                            ->helperText(fn (Get $get, string $operation): string => match (true) {
                                $operation === 'edit' => 'Lascia vuoto per mantenere la password salvata. La password salvata non viene mai mostrata.',
                                $isIcloud($get) => 'Non la password dell\'Apple ID: generala su appleid.apple.com → Accesso e sicurezza → Password per app.',
                                default => 'Viene salvata cifrata e non viene più mostrata.',
                            }),
                    ]),
            ]);
    }
}
