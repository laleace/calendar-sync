<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use App\Enums\PrivacyMode;
use App\Models\Calendar;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SyncJobForm
{
    public const INTERVALS = [
        5 => 'Ogni 5 minuti',
        15 => 'Ogni 15 minuti',
        30 => 'Ogni 30 minuti',
        60 => 'Ogni ora',
        360 => 'Ogni 6 ore',
        1440 => 'Una volta al giorno',
    ];

    /**
     * `source_ids` is a virtual field: the Create/Edit pages store it in the sources pivot,
     * keeping the selection order as priority.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Calendari')
                    ->columns(2)
                    ->schema([
                        Select::make('source_ids')
                            ->label('Calendari sorgente')
                            ->multiple()
                            ->options(fn (): array => Calendar::groupedOptions(Calendar::with('account')->whereNull('missing_since')->get()))
                            ->required()
                            ->live()
                            ->helperText('Se lo stesso evento è in più sorgenti, vince il primo calendario selezionato.'),

                        Select::make('target_calendar_id')
                            ->label('Calendario di destinazione')
                            ->options(fn (Get $get): array => Calendar::groupedOptions(
                                Calendar::with('account')->writable()->whereNotIn('id', $get('source_ids') ?? [])->get(),
                            ))
                            ->required()
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (in_array((int) $value, array_map('intval', $get('source_ids') ?? []), true)) {
                                        $fail('La destinazione non può essere anche una sorgente.');
                                    }
                                },
                            ])
                            ->helperText('Solo calendari scrivibili. Il job modifica e cancella esclusivamente gli eventi creati da lui.'),
                    ]),

                Section::make('Regole')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->placeholder('es. iCloud → Google lavoro')
                            ->required()
                            ->maxLength(100),

                        Select::make('interval_minutes')
                            ->label('Frequenza')
                            ->options(self::INTERVALS)
                            ->default(15)
                            ->selectablePlaceholder(false)
                            ->required(),

                        TextInput::make('range_past_days')
                            ->label('Giorni nel passato')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->default(7)
                            ->suffix('giorni')
                            ->required(),

                        TextInput::make('range_future_days')
                            ->label('Giorni nel futuro')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(730)
                            ->default(60)
                            ->suffix('giorni')
                            ->required(),

                        Toggle::make('enabled')
                            ->label('Attivo')
                            ->helperText('Se attivo, il job viene eseguito automaticamente con la frequenza indicata.')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),

                Section::make('Contenuto delle copie')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('privacy_mode')
                            ->label('Privacy')
                            ->options(PrivacyMode::class)
                            ->default(PrivacyMode::Full)
                            ->inline()
                            ->live()
                            ->required()
                            ->helperText('"Solo Occupato" copia solo gli orari: niente titolo, luogo o note. Gli eventi segnati come liberi vengono saltati.')
                            ->columnSpanFull(),

                        TextInput::make('busy_title')
                            ->label('Titolo dei blocchi')
                            ->default('Occupato')
                            ->required()
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => self::privacy($get) === PrivacyMode::BusyOnly),

                        TextInput::make('title_prefix')
                            ->label('Prefisso del titolo')
                            ->placeholder('es. [iCloud]')
                            ->maxLength(50),
                    ]),

                Section::make('Duplicati')
                    ->collapsed()
                    ->schema([
                        Toggle::make('allow_duplicates')
                            ->label('Consenti duplicati tra sorgenti')
                            ->helperText('Se disattivo (consigliato), un evento presente in più sorgenti con lo stesso UID, come un invito ricevuto su due account, produce una sola copia.'),

                        Toggle::make('skip_existing_in_target')
                            ->label('Salta gli eventi già presenti nella destinazione')
                            ->helperText('Non copia un evento se la destinazione contiene già un evento con lo stesso UID, per esempio l\'invito originale.'),
                    ]),
            ]);
    }

    private static function privacy(Get $get): ?PrivacyMode
    {
        $value = $get('privacy_mode');

        return $value instanceof PrivacyMode ? $value : PrivacyMode::tryFrom((string) $value);
    }
}
