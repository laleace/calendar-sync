<?php

namespace App\Console\Commands;

use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\ProviderRegistry;
use App\Models\Calendar;
use App\Support\DateFormat;
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
            $this->components->error(__('Calendar #:id not found.', ['id' => $this->argument('calendar')]));

            return self::FAILURE;
        }

        $timezone = config('calsync.timezone');

        try {
            $from = CarbonImmutable::parse($this->option('from') ?? 'today', $timezone)->startOfDay();
            $lastDay = $this->option('to') ? CarbonImmutable::parse($this->option('to'), $timezone) : $from->addDays(30);
        } catch (InvalidFormatException) {
            $this->components->error(__('Invalid dates: use the YYYY-MM-DD format.'));

            return self::FAILURE;
        }

        $to = $lastDay->addDay()->startOfDay();

        if ($to <= $from || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $this->components->error(__('Invalid range: "to" must follow "from" and the maximum range is :count days.', ['count' => self::MAX_RANGE_DAYS]));

            return self::FAILURE;
        }

        $this->components->info(__('Calendar #:id · :name (:account) · from :from to :to', [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'account' => $calendar->account->name,
            'from' => $from->format(DateFormat::date()),
            'to' => $lastDay->format(DateFormat::date()),
        ]));

        try {
            $events = $providers->for($calendar->account)->listEvents($calendar, $from, $to);
        } catch (ProviderException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (count($events) === 0) {
            $this->components->warn(__('No events in this range.'));
        } else {
            $this->table(
                [__('Start'), __('End'), __('Title'), __('Status'), __('Notes'), 'UID'],
                array_map(fn (EventDto $event) => $this->row($event, $timezone), $events->events),
            );
        }

        $collection = collect($events->events);
        $this->line('  '.__('Events: :count · Recurrence occurrences: :recurring · Created by calendar-sync: :marked', [
            'count' => $collection->count(),
            'recurring' => $collection->whereNotNull('recurrenceId')->count(),
            'marked' => $collection->filter->isMarked()->count(),
        ]));

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
            $start = $event->start->format(DateFormat::date());
            $end = $event->end->subDay()->format(DateFormat::date());
        } else {
            $start = $event->start->setTimezone($timezone)->format(DateFormat::dateTime());
            $end = $event->end->setTimezone($timezone)->format(DateFormat::dateTime());
        }

        $notes = array_filter([
            $event->allDay ? __('all day') : null,
            $event->recurrenceId !== null ? __('recurring') : null,
            $event->transparent ? __('free') : null,
            $event->isMarked() ? __('created by calendar-sync') : null,
            ! $event->allDay && $event->timezone !== $timezone ? $event->timezone : null,
        ]);

        return [
            $start,
            $end,
            Str::limit($event->title !== '' ? $event->title : __('(no title)'), 50),
            $event->status->getLabel(),
            implode(', ', $notes),
            Str::limit($event->uid, 24),
        ];
    }
}
