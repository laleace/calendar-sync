<?php

namespace App\CalSync\CalDav;

use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Markers;
use App\Enums\EventStatus;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;
use Throwable;

/**
 * iCalendar (ICS) → EventDto, with recurrences expanded inside a time window.
 */
final class ICalendarMapper
{
    /**
     * Iterations allowed per recurring event. vobject counts from the first occurrence,
     * not from the window: a daily event created years ago needs thousands of steps.
     */
    private const MAX_RECURRENCES = 50_000;

    /**
     * Parse one calendar object resource. Unreadable events are reported as problems
     * instead of exceptions, so one broken event does not hide the others.
     *
     * @param  DateTimeZone  $defaultTimezone  Used for floating times and all-day events.
     */
    public function parse(
        string $ics,
        DateTimeInterface $from,
        DateTimeInterface $to,
        DateTimeZone $defaultTimezone,
        ?string $remoteId = null,
        ?string $etag = null,
    ): EventList {
        $resource = $remoteId !== null ? rawurldecode(basename(parse_url($remoteId, PHP_URL_PATH) ?: $remoteId)) : 'ICS';

        try {
            $vcalendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            return new EventList(problems: ["Risorsa {$resource} non leggibile: {$e->getMessage()}"]);
        }

        if (! $vcalendar instanceof VCalendar) {
            return new EventList(problems: ["Risorsa {$resource} non leggibile: non è un VCALENDAR."]);
        }

        $events = [];
        $problems = [];

        foreach ($this->groupByUid($vcalendar) as $uid => $components) {
            try {
                foreach ($this->instances($components, $from, $to, $defaultTimezone) as [$vevent, $start, $end]) {
                    $events[] = $this->toDto((string) $uid, $vevent, $start, $end, $defaultTimezone, $remoteId, $etag);
                }
            } catch (MaxInstancesExceededException) {
                $problems[] = "Evento {$uid} ({$resource}): troppe ricorrenze da espandere.";
            } catch (Throwable $e) {
                $problems[] = "Evento {$uid} ({$resource}) non leggibile: {$e->getMessage()}";
            }
        }

        return new EventList($events, $problems);
    }

    /**
     * A resource holds one master VEVENT plus its overridden occurrences, all sharing a UID.
     *
     * @return array<string, list<VEvent>>
     */
    private function groupByUid(VCalendar $vcalendar): array
    {
        $groups = [];

        foreach ($vcalendar->select('VEVENT') as $vevent) {
            $uid = trim((string) $vevent->UID);
            // UID is mandatory (RFC 5545), but be lenient with broken producers.
            $groups[$uid !== '' ? $uid : 'no-uid-'.sha1($vevent->serialize())][] = $vevent;
        }

        return $groups;
    }

    /**
     * Occurrences overlapping [from, to), as [VEVENT, start, end].
     *
     * @param  list<VEvent>  $components
     * @return Generator<int, array{0: VEvent, 1: DateTimeImmutable, 2: DateTimeImmutable}>
     */
    private function instances(array $components, DateTimeInterface $from, DateTimeInterface $to, DateTimeZone $timezone): Generator
    {
        $master = null;
        $overrides = [];

        foreach ($components as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $overrides[] = $vevent;
            } else {
                $master ??= $vevent;
            }
        }

        if ($master !== null && (isset($master->RRULE) || isset($master->RDATE))) {
            yield from $this->expandRecurring($components, $from, $to, $timezone);

            return;
        }

        // Single event, or overridden occurrences stored without their master (CalDAV allows
        // it, e.g. when invited to one occurrence only): each one keeps its own RECURRENCE-ID.
        foreach (array_filter([$master, ...$overrides]) as $vevent) {
            [$start, $end] = $this->bounds($vevent, $timezone);

            if ($this->overlaps($start, $end, $from, $to)) {
                yield [$vevent, $start, $end];
            }
        }
    }

    /**
     * @param  list<VEvent>  $components
     * @return Generator<int, array{0: VEvent, 1: DateTimeImmutable, 2: DateTimeImmutable}>
     */
    private function expandRecurring(array $components, DateTimeInterface $from, DateTimeInterface $to, DateTimeZone $timezone): Generator
    {
        $previousLimit = Settings::$maxRecurrences;
        Settings::$maxRecurrences = self::MAX_RECURRENCES;

        try {
            $iterator = new EventIterator($components, null, $timezone);
            $iterator->fastForward($from);

            while ($iterator->valid() && $iterator->getDtStart() < $to) {
                yield [$iterator->getEventObject(), $iterator->getDtStart(), $iterator->getDtEnd()];
                $iterator->next();
            }
        } catch (NoInstancesException) {
            // Every occurrence excluded (EXDATE): nothing to return.
        } finally {
            Settings::$maxRecurrences = $previousLimit;
        }
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function bounds(VEvent $vevent, DateTimeZone $timezone): array
    {
        $start = $vevent->DTSTART->getDateTime($timezone);

        $end = match (true) {
            isset($vevent->DTEND) => $vevent->DTEND->getDateTime($timezone),
            isset($vevent->DURATION) => $start->add($vevent->DURATION->getDateInterval()),
            ! $vevent->DTSTART->hasTime() => $start->modify('+1 day'),
            default => $start,
        };

        return [$start, $end];
    }

    private function overlaps(DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $from, DateTimeInterface $to): bool
    {
        // Zero-duration events count when they start inside the window.
        $endsAfterFrom = $end > $from || ($end == $start && $start >= $from);

        return $endsAfterFrom && $start < $to;
    }

    private function toDto(
        string $uid,
        VEvent $vevent,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeZone $defaultTimezone,
        ?string $remoteId,
        ?string $etag,
    ): EventDto {
        $allDay = ! $vevent->DTSTART->hasTime();

        if ($allDay) {
            // vobject measures durations in seconds: across a DST change an all-day event
            // would end at 23:00 or 01:00. Count whole days instead.
            $days = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
            $start = $start->setTime(0, 0);
            $end = $start->modify("+{$days} days");
        }

        $timezone = $allDay || $vevent->DTSTART->isFloating()
            ? $defaultTimezone->getName()
            : $start->getTimezone()->getName();

        return new EventDto(
            uid: $uid,
            recurrenceId: $this->recurrenceId($vevent, $defaultTimezone),
            title: $this->text($vevent, 'SUMMARY') ?? '',
            start: CarbonImmutable::instance($start),
            end: CarbonImmutable::instance($end),
            allDay: $allDay,
            timezone: $timezone,
            location: $this->text($vevent, 'LOCATION'),
            description: $this->text($vevent, 'DESCRIPTION'),
            status: EventStatus::tryFrom(strtolower((string) $vevent->STATUS)) ?? EventStatus::Confirmed,
            transparent: strtoupper((string) $vevent->TRANSP) === 'TRANSPARENT',
            markers: array_filter([
                'job' => $this->text($vevent, Markers::CALDAV_JOB),
                'source' => $this->text($vevent, Markers::CALDAV_SOURCE),
                'key' => $this->text($vevent, Markers::CALDAV_KEY),
                'uid' => Markers::isMarkerUid($uid) ? $uid : null,
            ]),
            remoteId: $remoteId,
            etag: $etag,
        );
    }

    /**
     * ICS for a copy on a CalDAV calendar: one VEVENT, no attendees or organizer (the server
     * would send invitations), no alarms, calendar-sync markers as X- properties.
     * Timed events are written in UTC, so no VTIMEZONE is needed.
     */
    public function toIcs(EventDto $event): string
    {
        $vcalendar = new VCalendar(['PRODID' => '-//calendar-sync//EN']);
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => $event->uid,
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'SUMMARY' => $event->title,
            'STATUS' => strtoupper($event->status->value),
            'TRANSP' => $event->transparent ? 'TRANSPARENT' : 'OPAQUE',
        ]);

        if ($event->allDay) {
            $vevent->add('DTSTART', $event->start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->end->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $event->start->utc()->toDateTimeImmutable());
            $vevent->add('DTEND', $event->end->utc()->toDateTimeImmutable());
        }

        foreach (['LOCATION' => $event->location, 'DESCRIPTION' => $event->description] as $name => $value) {
            if ($value !== null) {
                $vevent->add($name, $value);
            }
        }

        foreach ([Markers::CALDAV_JOB => 'job', Markers::CALDAV_SOURCE => 'source', Markers::CALDAV_KEY => 'key'] as $property => $marker) {
            if (isset($event->markers[$marker])) {
                $vevent->add($property, $event->markers[$marker]);
            }
        }

        return $vcalendar->serialize();
    }

    /**
     * The job marker of a stored calendar object, null when absent or unreadable.
     */
    public function jobMarker(string $ics): ?string
    {
        try {
            $vcalendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return null;
        }

        foreach ($vcalendar->select('VEVENT') as $vevent) {
            if ($job = $this->text($vevent, Markers::CALDAV_JOB)) {
                return $job;
            }
        }

        return null;
    }

    /**
     * "20261007T070000Z" for timed occurrences (always UTC), "20261007" for all-day ones.
     */
    private function recurrenceId(VEvent $vevent, DateTimeZone $timezone): ?string
    {
        $property = $vevent->{'RECURRENCE-ID'};

        if ($property === null) {
            return null;
        }

        $original = $property->getDateTime($timezone);

        return $property->hasTime()
            ? $original->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
            : $original->format('Ymd');
    }

    private function text(VEvent $vevent, string $name): ?string
    {
        $value = trim((string) $vevent->{$name}?->getValue());

        return $value === '' ? null : $value;
    }
}
