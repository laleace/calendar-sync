<?php

namespace App\Filament\Pages;

use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\ProviderRegistry;
use App\Enums\EventStatus;
use App\Models\Calendar;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Read-only preview of the events of a calendar, as the sync engine will see them
 * (recurrences expanded, markers flagged).
 */
class CalendarPreview extends Page implements HasTable
{
    use InteractsWithTable;

    public const MAX_RANGE_DAYS = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Anteprima eventi';

    protected static ?string $title = 'Anteprima eventi';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.calendar-preview';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<string> */
    public array $problems = [];

    public bool $loaded = false;

    public function mount(ProviderRegistry $providers): void
    {
        $calendarId = request()->integer('calendar') ?: null;
        $today = CarbonImmutable::today(config('calsync.timezone'));

        $this->form->fill([
            'calendar_id' => $calendarId,
            'from' => $today->toDateString(),
            'to' => $today->addDays(30)->toDateString(),
        ]);

        // Opened from a calendar row: show its events straight away.
        if ($calendarId !== null && Calendar::whereKey($calendarId)->exists()) {
            $this->load($providers);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(4)
            ->components([
                Select::make('calendar_id')
                    ->label('Calendario')
                    ->options(fn (): array => self::calendarOptions())
                    ->searchable()
                    ->required()
                    ->columnSpan(2),
                DatePicker::make('from')
                    ->label('Dal')
                    ->required(),
                DatePicker::make('to')
                    ->label('Al (incluso)')
                    ->required()
                    ->afterOrEqual('from'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('load')
                    ->footer([
                        Actions::make([
                            Action::make('load')
                                ->label('Mostra eventi')
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->submit('load'),
                        ]),
                    ]),
                Callout::make('Alcuni eventi non sono leggibili')
                    ->description(fn (): string => implode(' · ', $this->problems))
                    ->warning()
                    ->visible(fn (): bool => $this->problems !== []),
                EmbeddedTable::make()
                    ->visible(fn (): bool => $this->loaded),
            ]);
    }

    public function load(ProviderRegistry $providers): void
    {
        $data = $this->form->getState();
        $timezone = config('calsync.timezone');

        $from = CarbonImmutable::parse($data['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], $timezone)->addDay()->startOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'data.to' => 'Il periodo massimo è di '.self::MAX_RANGE_DAYS.' giorni.',
            ]);
        }

        $calendar = Calendar::with('account')->findOrFail($data['calendar_id']);

        try {
            $list = $providers->for($calendar->account)->listEvents($calendar, $from, $to);
        } catch (ProviderException $e) {
            [$this->events, $this->problems, $this->loaded] = [[], [], false];

            Notification::make()
                ->title('Lettura non riuscita')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->events = array_map(
            fn (EventDto $event, int $index): array => $this->toRow($event, $index, $timezone),
            $list->events,
            array_keys($list->events),
        );
        $this->problems = $list->problems;
        $this->loaded = true;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $search): LengthAwarePaginator => $this->paginateEvents($page, $recordsPerPage, $search))
            ->columns([
                TextColumn::make('when')
                    ->label('Quando'),
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->description(fn (array $record): ?string => $record['location'])
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EventStatus::from($state)->getLabel())
                    ->color(fn (string $state): string => EventStatus::from($state)->getColor()),
                TextColumn::make('flags')
                    ->label('Note')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('timezone')
                    ->label('Timezone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uid')
                    ->label('UID')
                    ->limit(30)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recurrence_id')
                    ->label('Recurrence ID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nessun evento nel periodo');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function calendarOptions(): array
    {
        return Calendar::groupedOptions(Calendar::with('account.group')->whereNull('missing_since')->get());
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EventDto $event, int $index, string $timezone): array
    {
        if ($event->allDay) {
            $lastDay = $event->end->subDay();
            $when = $event->start->format('d/m/Y').($lastDay->isSameDay($event->start) ? '' : ' → '.$lastDay->format('d/m/Y'));
        } else {
            $start = $event->start->setTimezone($timezone);
            $end = $event->end->setTimezone($timezone);
            $when = $start->format('d/m/Y H:i').' → '.($end->isSameDay($start) ? $end->format('H:i') : $end->format('d/m/Y H:i'));
        }

        return [
            ArrayRecord::getKeyName() => (string) $index,
            'when' => $when,
            'title' => $event->title !== '' ? $event->title : '(senza titolo)',
            'location' => $event->location,
            'status' => $event->status->value,
            'flags' => array_values(array_filter([
                $event->allDay ? 'tutto il giorno' : null,
                $event->recurrenceId !== null ? 'ricorrente' : null,
                $event->transparent ? 'libero' : null,
                $event->isMarked() ? 'creato da calendar-sync' : null,
                ! $event->allDay && $event->timezone !== $timezone ? $event->timezone : null,
            ])),
            'timezone' => $event->timezone,
            'uid' => $event->uid,
            'recurrence_id' => $event->recurrenceId,
        ];
    }

    private function paginateEvents(int $page, int|string $perPage, ?string $search): LengthAwarePaginator
    {
        $rows = collect($this->events);

        if (filled($search)) {
            $rows = $rows->filter(fn (array $row): bool => Str::contains($row['title'].' '.$row['location'], $search, ignoreCase: true));
        }

        $perPage = $perPage === 'all' ? max(1, $rows->count()) : (int) $perPage;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values()->all(), $rows->count(), $perPage, $page);
    }
}
