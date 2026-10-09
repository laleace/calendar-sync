<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use App\CalSync\Sync\FilterRule;
use App\Enums\FilterField;
use App\Enums\PrivacyMode;
use App\Enums\TextOperator;
use App\Models\Calendar;
use App\Models\SyncJob;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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

                Section::make('Filtri')
                    ->description("Facoltativi. Valgono per tutte le sorgenti e guardano l'evento originale, prima di privacy e prefisso. Prova l'effetto con il dry-run.")
                    ->statePath('filters')
                    ->collapsible()
                    ->collapsed(fn (?SyncJob $record): bool => ($record?->eventFilters()->count() ?? 0) === 0)
                    ->schema([
                        self::rules('include', 'Copia solo gli eventi che…')
                            ->helperText('Se non ci sono regole vengono copiati tutti gli eventi.'),

                        ToggleButtons::make('include_mode')
                            ->label('Le regole sopra devono valere')
                            ->options(['any' => 'Almeno una', 'all' => 'Tutte'])
                            ->formatStateUsing(fn (?string $state): string => $state ?? 'any')
                            ->inline()
                            ->visible(fn (Get $get): bool => count($get('include') ?? []) > 1),

                        Toggle::make('strip_prefix')
                            ->label('Togli il prefisso dal titolo della copia')
                            ->helperText('Per le regole «Titolo inizia con»: «[Lavoro] Riunione» diventa «Riunione».')
                            ->visible(fn (Get $get): bool => self::hasPrefixRule($get('include') ?? [])),

                        self::rules('exclude', 'Escludi gli eventi che…')
                            ->helperText("Basta una regola per escludere l'evento, anche se rispetta quelle sopra."),
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

    private static function rules(string $name, string $label): Repeater
    {
        $field = fn (Get $get): ?FilterField => FilterField::tryFrom(self::value($get('field')));

        return Repeater::make($name)
            ->label($label)
            ->schema([
                Select::make('field')
                    ->label('Campo')
                    ->options(FilterField::class)
                    ->required()
                    ->live(),

                Select::make('operator')
                    ->label('Condizione')
                    ->options(TextOperator::class)
                    ->default(TextOperator::Contains->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => $field($get)?->isText() ?? false),

                TextInput::make('value')
                    ->label('Testo')
                    ->required()
                    ->maxLength(200)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (self::value($get('operator')) === TextOperator::Regex->value && ! FilterRule::isValidPattern(trim((string) $value))) {
                                $fail('Espressione regolare non valida. Scrivila senza delimitatori, per esempio ^\\[(Lavoro|Work)\\]');
                            }
                        },
                    ])
                    ->visible(fn (Get $get): bool => $field($get)?->isText() ?? false),

                ToggleButtons::make('weekdays')
                    ->label('Giorni di inizio')
                    ->options(array_map('ucfirst', FilterRule::WEEKDAYS))
                    ->multiple()
                    ->inline()
                    ->required()
                    ->columnSpan(2)
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::Weekday),

                // Times of day, not instants: same timezone as the state, so no conversion.
                TimePicker::make('from')
                    ->label('Dalle')
                    ->seconds(false)
                    ->timezone(fn (): string => config('app.timezone'))
                    ->required()
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::StartTime),

                TimePicker::make('to')
                    ->label('Alle (escluse)')
                    ->seconds(false)
                    ->timezone(fn (): string => config('app.timezone'))
                    ->required()
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (FilterRule::minuteOfDay($value) === FilterRule::minuteOfDay($get('from'))) {
                                $fail("L'orario finale deve essere diverso da quello iniziale.");
                            }
                        },
                    ])
                    ->helperText('Se è prima di «Dalle», la fascia scavalca la mezzanotte.')
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::StartTime),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable(false)
            ->addActionLabel('Aggiungi regola')
            ->itemLabel(fn (array $state): ?string => FilterRule::fromArray($state)?->describe());
    }

    /**
     * @param  array<array-key, mixed>  $rules
     */
    private static function hasPrefixRule(array $rules): bool
    {
        return collect($rules)->contains(fn ($rule): bool => is_array($rule)
            && self::value($rule['field'] ?? null) === FilterField::Title->value
            && self::value($rule['operator'] ?? null) === TextOperator::StartsWith->value);
    }

    private static function value(mixed $state): string
    {
        return $state instanceof BackedEnum ? (string) $state->value : (string) $state;
    }

    private static function privacy(Get $get): ?PrivacyMode
    {
        $value = $get('privacy_mode');

        return $value instanceof PrivacyMode ? $value : PrivacyMode::tryFrom((string) $value);
    }
}
