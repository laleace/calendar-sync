<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use App\CalSync\Sync\FilterRule;
use App\Enums\FilterField;
use App\Enums\PrivacyMode;
use App\Enums\TextOperator;
use App\Filament\Support\GroupFields;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SyncJobForm
{
    /**
     * Frequency presets, in minutes.
     *
     * @return array<int, string>
     */
    public static function intervals(): array
    {
        return [
            5 => __('Every :count minutes', ['count' => 5]),
            15 => __('Every :count minutes', ['count' => 15]),
            30 => __('Every :count minutes', ['count' => 30]),
            60 => __('Every hour'),
            360 => __('Every :count hours', ['count' => 6]),
            1440 => __('Once a day'),
        ];
    }

    /**
     * `source_ids` is a virtual field: the Create/Edit pages store it in the sources pivot,
     * keeping the selection order as priority.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Calendars'))
                    ->columns(2)
                    ->schema([
                        Select::make('source_ids')
                            ->label(__('Source calendars'))
                            ->multiple()
                            ->options(fn (): array => Calendar::groupedOptions(Calendar::with('account.group')->whereNull('missing_since')->get()))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestGroup($get, $set))
                            ->helperText(__('If the same event is in more than one source, the first selected calendar wins.')),

                        Select::make('target_calendar_id')
                            ->label(__('Target calendar'))
                            ->options(fn (Get $get): array => Calendar::groupedOptions(
                                Calendar::with('account.group')->writable()->whereNotIn('id', $get('source_ids') ?? [])->get(),
                            ))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestGroup($get, $set))
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (in_array((int) $value, array_map('intval', $get('source_ids') ?? []), true)) {
                                        $fail(__('The target cannot also be a source.'));
                                    }
                                },
                            ])
                            ->helperText(__('Writable calendars only. The job only changes and deletes the events it created.')),
                    ]),

                Section::make(__('Settings'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('e.g. iCloud → Google work'))
                            ->required()
                            ->maxLength(100),

                        GroupFields::select()
                            ->helperText(__('Only used to organise the panel. If empty and all the chosen calendars belong to accounts of the same group, that group is suggested.')),

                        TextInput::make('range_past_days')
                            ->label(__('Days in the past'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->default(7)
                            ->suffix(__('days'))
                            ->required(),

                        TextInput::make('range_future_days')
                            ->label(__('Days in the future'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(730)
                            ->default(60)
                            ->suffix(__('days'))
                            ->required(),

                        Select::make('interval_minutes')
                            ->label(__('Frequency'))
                            ->options(self::intervals())
                            ->default(15)
                            ->selectablePlaceholder(false)
                            ->required(),

                        Toggle::make('enabled')
                            ->label(__('Enabled'))
                            ->helperText(__('When enabled, the job runs automatically at the chosen frequency.'))
                            ->default(false)
                            ->inline(false),
                    ]),

                Section::make(__('Copy content'))
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('privacy_mode')
                            ->label('Privacy')
                            ->options(PrivacyMode::class)
                            ->default(PrivacyMode::Full)
                            ->inline()
                            ->live()
                            ->required()
                            ->helperText(__('"Busy only" copies only the times: no title, location or notes. Events marked as free are skipped.'))
                            ->columnSpanFull(),

                        TextInput::make('busy_title')
                            ->label(__('Block title'))
                            ->default(fn (): string => __('Busy'))
                            ->required()
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => self::privacy($get) === PrivacyMode::BusyOnly),

                        TextInput::make('title_prefix')
                            ->label(__('Title prefix'))
                            ->placeholder(__('e.g. [iCloud]'))
                            ->maxLength(50),
                    ]),

                Section::make(__('Filters'))
                    ->description(__('Optional. They apply to all sources and look at the original event, before privacy and prefix. Check the effect with a dry-run.'))
                    ->statePath('filters')
                    ->collapsible()
                    ->collapsed(fn (?SyncJob $record): bool => ($record?->eventFilters()->count() ?? 0) === 0)
                    ->schema([
                        self::rules('include', __('Only copy events that…'))
                            ->helperText(__('Without rules, all events are copied.')),

                        ToggleButtons::make('include_mode')
                            ->label(__('The rules above must match'))
                            ->options(['any' => __('At least one'), 'all' => __('All')])
                            ->formatStateUsing(fn (?string $state): string => $state ?? 'any')
                            ->inline()
                            ->visible(fn (Get $get): bool => count($get('include') ?? []) > 1),

                        Toggle::make('strip_prefix')
                            ->label(__('Remove the prefix from the copy title'))
                            ->helperText(__('For "Title starts with" rules: "[Work] Meeting" becomes "Meeting".'))
                            ->visible(fn (Get $get): bool => self::hasPrefixRule($get('include') ?? [])),

                        self::rules('exclude', __('Exclude events that…'))
                            ->helperText(__('One matching rule is enough to exclude the event, even if it matches the rules above.')),
                    ]),

                Section::make(__('Duplicates'))
                    ->collapsed()
                    ->schema([
                        Toggle::make('allow_duplicates')
                            ->label(__('Allow duplicates across sources'))
                            ->helperText(__('When off (recommended), an event found in more than one source with the same UID, such as an invitation received on two accounts, produces a single copy.')),

                        Toggle::make('skip_existing_in_target')
                            ->label(__('Skip events already in the target'))
                            ->helperText(__('Does not copy an event if the target already contains an event with the same UID, such as the original invitation.')),
                    ]),
            ]);
    }

    /**
     * Fills an empty group when the sources and the target all belong to accounts of the
     * same group.
     */
    private static function suggestGroup(Get $get, Set $set): void
    {
        if (filled($get('group_id')) || blank($get('source_ids')) || blank($get('target_calendar_id'))) {
            return;
        }

        $groups = Calendar::with('account')
            ->whereIn('id', [...$get('source_ids'), $get('target_calendar_id')])
            ->get()
            ->map(fn (Calendar $calendar) => $calendar->account->group_id)
            ->unique();

        if ($groups->count() === 1 && $groups->first() !== null) {
            $set('group_id', $groups->first());
        }
    }

    private static function rules(string $name, string $label): Repeater
    {
        $field = fn (Get $get): ?FilterField => FilterField::tryFrom(self::value($get('field')));

        return Repeater::make($name)
            ->label($label)
            ->schema([
                Select::make('field')
                    ->label(__('Field'))
                    ->options(FilterField::class)
                    ->required()
                    ->live(),

                Select::make('operator')
                    ->label(__('Condition'))
                    ->options(TextOperator::class)
                    ->default(TextOperator::Contains->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => $field($get)?->isText() ?? false),

                TextInput::make('value')
                    ->label(__('Text'))
                    ->required()
                    ->maxLength(200)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (self::value($get('operator')) === TextOperator::Regex->value && ! FilterRule::isValidPattern(trim((string) $value))) {
                                $fail(__('Invalid regular expression. Write it without delimiters, for example ^\\[(Work|Lavoro)\\]'));
                            }
                        },
                    ])
                    ->visible(fn (Get $get): bool => $field($get)?->isText() ?? false),

                ToggleButtons::make('weekdays')
                    ->label(__('Start days'))
                    ->options(array_map('ucfirst', FilterRule::weekdays()))
                    ->multiple()
                    ->inline()
                    ->required()
                    ->columnSpan(2)
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::Weekday),

                // Times of day, not instants: same timezone as the state, so no conversion.
                TimePicker::make('from')
                    ->label(__('From'))
                    ->seconds(false)
                    ->timezone(fn (): string => config('app.timezone'))
                    ->required()
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::StartTime),

                TimePicker::make('to')
                    ->label(__('To (excluded)'))
                    ->seconds(false)
                    ->timezone(fn (): string => config('app.timezone'))
                    ->required()
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (FilterRule::minuteOfDay($value) === FilterRule::minuteOfDay($get('from'))) {
                                $fail(__('The end time must differ from the start time.'));
                            }
                        },
                    ])
                    ->helperText(__('If it is earlier than "From", the range spans midnight.'))
                    ->visible(fn (Get $get): bool => $field($get) === FilterField::StartTime),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable(false)
            ->addActionLabel(__('Add rule'))
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
