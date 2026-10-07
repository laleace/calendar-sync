<?php

namespace App\Console\Commands;

use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\ProviderRegistry;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('calsync:list-events
    {calendar : Calendar id (see calsync:test-account)}
    {--from= : First day, YYYY-MM-DD (default: today)}
    {--to= : Last day included, YYYY-MM-DD (default: from + 30 days)}')]
#[Description('List the events of a calendar in a date range, recurrences expanded')]
class ListEvents extends Command
{
    private const MAX_RANGE_DAYS = 366;

    public function handle(ProviderRegistry $providers): int
    {
        $calendar = Calendar::with('account')->find($this->argument('calendar'));

        if ($calendar === null) {
            $this->components->error("Calendario #{$this->argument('calendar')} non trovato.");

            return self::FAILURE;
        }

        $timezone = config('calsync.timezone');

        try {
            $from = CarbonImmutable::parse($this->option('from') ?? 'today', $timezone)->startOfDay();
            $lastDay = $this->option('to') ? CarbonImmutable::parse($this->option('to'), $timezone) : $from->addDays(30);
        } catch (InvalidFormatException) {
            $this->components->error('Date non valide: usa il formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $to = $lastDay->addDay()->startOfDay();

        if ($to <= $from || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $this->components->error('Intervallo non valido: "to" deve seguire "from" e il range massimo è di '.self::MAX_RANGE_DAYS.' giorni.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Calendario #%d · %s (%s) · dal %s al %s',
            $calendar->id,
            $calendar->name,
            $calendar->account->name,
            $from->format('d/m/Y'),
            $lastDay->format('d/m/Y'),
        ));

        try {
            $events = $providers->for($calendar->account)->listEvents($calendar, $from, $to);
        } catch (ProviderException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (count($events) === 0) {
            $this->components->warn('Nessun evento nel periodo.');
        } else {
            $this->table(
                ['Inizio', 'Fine', 'Titolo', 'Stato', 'Note', 'UID'],
                array_map(fn (EventDto $event) => $this->row($event, $timezone), $events->events),
            );
        }

        $collection = collect($events->events);
        $this->line(sprintf(
            '  Eventi: %d · Occorrenze di ricorrenze: %d · Creati da calendar-sync: %d',
            $collection->count(),
            $collection->whereNotNull('recurrenceId')->count(),
            $collection->filter->isMarked()->count(),
        ));

        foreach ($events->problems as $problem) {
            $this->components->warn($problem);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(EventDto $event, string $timezone): array
    {
        if ($event->allDay) {
            $start = $event->start->format('d/m/Y');
            $end = $event->end->subDay()->format('d/m/Y');
        } else {
            $start = $event->start->setTimezone($timezone)->format('d/m/Y H:i');
            $end = $event->end->setTimezone($timezone)->format('d/m/Y H:i');
        }

        $notes = array_filter([
            $event->allDay ? 'tutto il giorno' : null,
            $event->recurrenceId !== null ? 'ricorrente' : null,
            $event->transparent ? 'libero' : null,
            $event->isMarked() ? 'creato da calendar-sync' : null,
            ! $event->allDay && $event->timezone !== $timezone ? $event->timezone : null,
        ]);

        return [
            $start,
            $end,
            Str::limit($event->title !== '' ? $event->title : '(senza titolo)', 50),
            $event->status->getLabel(),
            implode(', ', $notes),
            Str::limit($event->uid, 24),
        ];
    }
}
