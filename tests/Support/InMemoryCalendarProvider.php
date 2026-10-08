<?php

namespace Tests\Support;

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Contracts\WritableCalendarProvider;
use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Dto\WrittenEvent;
use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Google\GoogleProvider;
use App\CalSync\Markers;
use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;

/**
 * Calendars kept in memory, standing in for every provider type. Copies written by the
 * engine are stored with their markers and come back from listEvents, like on a real
 * server, so loop prevention can be tested.
 */
final class InMemoryCalendarProvider implements CalendarProvider, WritableCalendarProvider
{
    /** @var array<string, array<string, EventDto>> calendar remote_id => event id => event */
    public array $calendars = [];

    /** @var array<string, ProviderException> calendar remote_id => error thrown by listEvents */
    public array $readFailures = [];

    /** @var array<string, list<string>> calendar remote_id => problems reported by listEvents */
    public array $readProblems = [];

    /** @var array<string, ProviderException> calendar remote_id => error thrown by writes */
    public array $writeFailures = [];

    /** @var list<array{op: string, calendar: string, id: string}> */
    public array $writes = [];

    private int $sequence = 0;

    /**
     * Use this fake for every account type.
     */
    public static function install(): self
    {
        $provider = new self;
        app()->instance(CalDavProvider::class, $provider);
        app()->instance(GoogleProvider::class, $provider);

        return $provider;
    }

    public static function event(string $uid, string $title, string $start, string $end, array $overrides = []): EventDto
    {
        return new EventDto(...array_merge([
            'uid' => $uid,
            'recurrenceId' => null,
            'title' => $title,
            'start' => CarbonImmutable::parse($start, 'Europe/Rome'),
            'end' => CarbonImmutable::parse($end, 'Europe/Rome'),
            'allDay' => false,
            'timezone' => 'Europe/Rome',
        ], $overrides));
    }

    /**
     * Put an event on a calendar, as if the user created it there.
     */
    public function seed(Calendar $calendar, EventDto $event): EventDto
    {
        $id = $event->remoteId ?? 'evt-'.(++$this->sequence);
        $stored = $this->withId($event, $id);
        $this->calendars[$calendar->remote_id][$id] = $stored;

        return $stored;
    }

    public function remove(Calendar $calendar, string $uid): void
    {
        $this->calendars[$calendar->remote_id] = array_filter(
            $this->calendars[$calendar->remote_id] ?? [],
            fn (EventDto $event) => $event->uid !== $uid,
        );
    }

    /**
     * @return list<EventDto>
     */
    public function eventsOn(Calendar $calendar): array
    {
        return array_values($this->calendars[$calendar->remote_id] ?? []);
    }

    public function testConnection(Account $account): ConnectionResult
    {
        return ConnectionResult::ok('in memoria');
    }

    public function discoverCalendars(Account $account): DiscoveryResult
    {
        return new DiscoveryResult([]);
    }

    public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList
    {
        if (isset($this->readFailures[$calendar->remote_id])) {
            throw $this->readFailures[$calendar->remote_id];
        }

        $events = array_values(array_filter(
            $this->eventsOn($calendar),
            fn (EventDto $event) => $event->end > $from && $event->start < $to,
        ));

        return (new EventList($events, $this->readProblems[$calendar->remote_id] ?? []))->sorted();
    }

    public function createEvent(Calendar $calendar, EventDto $event): WrittenEvent
    {
        $this->failWritesIfConfigured($calendar);
        $id = isset($event->markers['job'], $event->markers['key'])
            ? Markers::copyHash($event->markers['job'], $event->markers['key'])
            : 'evt-'.(++$this->sequence);

        $existing = $this->calendars[$calendar->remote_id][$id] ?? null;

        if ($existing !== null && ($existing->markers['job'] ?? null) !== ($event->markers['job'] ?? null)) {
            throw new ProviderException("id {$id} già usato da un altro evento");
        }

        $this->calendars[$calendar->remote_id][$id] = $this->withId($event, $id);
        $this->writes[] = ['op' => 'create', 'calendar' => $calendar->remote_id, 'id' => $id];

        return new WrittenEvent($id, '"'.(++$this->sequence).'"');
    }

    public function updateEvent(Calendar $calendar, string $remoteId, EventDto $event): WrittenEvent
    {
        $this->failWritesIfConfigured($calendar);

        if (! isset($this->calendars[$calendar->remote_id][$remoteId])) {
            throw new EventNotFound("Evento {$remoteId} non trovato");
        }

        $this->calendars[$calendar->remote_id][$remoteId] = $this->withId($event, $remoteId);
        $this->writes[] = ['op' => 'update', 'calendar' => $calendar->remote_id, 'id' => $remoteId];

        return new WrittenEvent($remoteId, '"'.(++$this->sequence).'"');
    }

    public function deleteEvent(Calendar $calendar, string $remoteId): void
    {
        $this->failWritesIfConfigured($calendar);
        unset($this->calendars[$calendar->remote_id][$remoteId]);
        $this->writes[] = ['op' => 'delete', 'calendar' => $calendar->remote_id, 'id' => $remoteId];
    }

    private function failWritesIfConfigured(Calendar $calendar): void
    {
        if (isset($this->writeFailures[$calendar->remote_id])) {
            throw $this->writeFailures[$calendar->remote_id];
        }
    }

    private function withId(EventDto $event, string $id): EventDto
    {
        return new EventDto(
            uid: $event->uid,
            recurrenceId: $event->recurrenceId,
            title: $event->title,
            start: $event->start,
            end: $event->end,
            allDay: $event->allDay,
            timezone: $event->timezone,
            location: $event->location,
            description: $event->description,
            status: $event->status ?? EventStatus::Confirmed,
            transparent: $event->transparent,
            markers: $event->markers,
            remoteId: $id,
            etag: $event->etag,
            declined: $event->declined,
        );
    }
}
