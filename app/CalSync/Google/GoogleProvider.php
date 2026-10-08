<?php

namespace App\CalSync\Google;

use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Contracts\WritableCalendarProvider;
use App\CalSync\Dto\CalendarDto;
use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Dto\WrittenEvent;
use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Markers;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Google\Service\Calendar as CalendarService;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

final class GoogleProvider implements CalendarProvider, WritableCalendarProvider
{
    private const WRITE_ROLES = ['owner', 'writer'];

    /** Writes never notify attendees (copies have none anyway). */
    private const WRITE_OPTIONS = ['sendUpdates' => 'none'];

    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly GoogleEventMapper $mapper,
    ) {}

    public function testConnection(Account $account): ConnectionResult
    {
        try {
            $primary = $this->call(fn () => $this->service($account)->calendarList->get('primary'));
        } catch (ProviderException $e) {
            return ConnectionResult::failed($e->accountStatus(), $e->getMessage());
        }

        return ConnectionResult::ok("Connessione riuscita come {$primary->getId()}.");
    }

    public function discoverCalendars(Account $account): DiscoveryResult
    {
        $service = $this->service($account);
        $calendars = [];
        $pageToken = null;

        do {
            $page = $this->call(fn () => $service->calendarList->listCalendarList(array_filter([
                'showHidden' => true,
                'maxResults' => 250,
                'pageToken' => $pageToken,
            ])));

            foreach ($page->getItems() as $entry) {
                // freeBusyReader calendars expose busy slots only, not events.
                if ($entry->getDeleted() || $entry->getAccessRole() === 'freeBusyReader') {
                    continue;
                }

                $calendars[] = new CalendarDto(
                    remoteId: $entry->getId(),
                    name: $entry->getSummaryOverride() ?: (string) $entry->getSummary(),
                    canWrite: in_array($entry->getAccessRole(), self::WRITE_ROLES, true),
                    color: $entry->getBackgroundColor() ? strtoupper($entry->getBackgroundColor()) : null,
                    timezone: $entry->getTimeZone(),
                );
            }

            $pageToken = $page->getNextPageToken();
        } while ($pageToken !== null);

        return new DiscoveryResult($calendars);
    }

    public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList
    {
        $service = $this->service($calendar->account);
        $events = [];
        $problems = [];
        $pageToken = null;

        do {
            $page = $this->call(fn () => $service->events->listEvents($calendar->remote_id, array_filter([
                'singleEvents' => true,
                // timeMin bounds the end, timeMax the start: same overlap rule as the CalDAV side.
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'showDeleted' => false,
                // Only the account owner among the attendees: enough to know if they declined.
                'maxAttendees' => 1,
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ])));

            $timezone = $this->calendarTimezone($page->getTimeZone() ?: $calendar->timezone);

            foreach ($page->getItems() as $item) {
                try {
                    if ($dto = $this->mapper->toDto($item, $timezone)) {
                        $events[] = $dto;
                    }
                } catch (Throwable $e) {
                    $problems[] = "Evento {$item->getId()} non leggibile: {$e->getMessage()}";
                }
            }

            $pageToken = $page->getNextPageToken();
        } while ($pageToken !== null);

        return (new EventList($events, $problems))->sorted();
    }

    public function createEvent(Calendar $calendar, EventDto $event): WrittenEvent
    {
        $service = $this->service($calendar->account);
        $body = $this->mapper->toGoogle($event);
        $jobId = $event->markers['job'] ?? null;
        $id = $jobId !== null && isset($event->markers['key']) ? Markers::googleEventId($jobId, $event->markers['key']) : null;

        if ($id !== null) {
            $body->setId($id);
        }

        try {
            $created = $service->events->insert($calendar->remote_id, $body, self::WRITE_OPTIONS);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() !== 409 || $id === null) {
                throw GoogleErrors::map($e);
            }

            // The id is taken: an earlier run created this copy (maybe deleted since, Google
            // keeps deleted ids). Take it over only if it carries this job's marker.
            $existing = $this->call(fn () => $service->events->get($calendar->remote_id, $id));

            if (! GoogleEventMapper::isOwnedBy($existing, $jobId)) {
                throw new ProviderException("Sul calendario esiste già un evento con id {$id} non creato da questo job.");
            }

            $body->setId(null);
            $created = $this->call(fn () => $service->events->update($calendar->remote_id, $id, $body, self::WRITE_OPTIONS));
        }

        return new WrittenEvent($created->getId(), $created->getEtag());
    }

    public function updateEvent(Calendar $calendar, string $remoteId, EventDto $event): WrittenEvent
    {
        $service = $this->service($calendar->account);
        $updated = $this->call(fn () => $service->events->update($calendar->remote_id, $remoteId, $this->mapper->toGoogle($event), self::WRITE_OPTIONS));

        return new WrittenEvent($updated->getId(), $updated->getEtag());
    }

    public function deleteEvent(Calendar $calendar, string $remoteId): void
    {
        $service = $this->service($calendar->account);

        try {
            $this->call(fn () => $service->events->delete($calendar->remote_id, $remoteId, self::WRITE_OPTIONS));
        } catch (EventNotFound) {
            // Already gone.
        }
    }

    private function service(Account $account): CalendarService
    {
        return new CalendarService($this->clients->forAccount($account));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (Throwable $e) {
            throw GoogleErrors::map($e);
        }
    }

    private function calendarTimezone(?string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name ?: config('calsync.timezone'));
        } catch (\Exception) {
            return new DateTimeZone(config('calsync.timezone'));
        }
    }
}
