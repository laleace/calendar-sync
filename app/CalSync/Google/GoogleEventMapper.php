<?php

namespace App\CalSync\Google;

use App\CalSync\Dto\EventDto;
use App\CalSync\Markers;
use App\Enums\EventStatus;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventExtendedProperties;
use Google\Service\Calendar\EventReminders;

/**
 * Google Calendar event ↔ EventDto. Events are listed with singleEvents=true, so
 * recurring series already come expanded into instances.
 */
final class GoogleEventMapper
{
    /** Google limits extended property values to 1024 characters. */
    private const MAX_PROPERTY_LENGTH = 1024;

    /**
     * Null for events that are not real appointments: cancelled instances and
     * "working location" entries (Home/Office markers).
     */
    public function toDto(Event $event, DateTimeZone $calendarTimezone): ?EventDto
    {
        if ($event->getStatus() === 'cancelled' || $event->getEventType() === 'workingLocation') {
            return null;
        }

        $allDay = filled($event->getStart()->getDate());

        if ($allDay) {
            $timezone = $calendarTimezone;
            $start = CarbonImmutable::parse($event->getStart()->getDate(), $timezone)->startOfDay();
            $end = CarbonImmutable::parse($event->getEnd()->getDate(), $timezone)->startOfDay();
        } else {
            $timezone = $this->timezone($event->getStart()->getTimeZone(), $calendarTimezone);
            $start = CarbonImmutable::parse($event->getStart()->getDateTime())->setTimezone($timezone);
            $end = CarbonImmutable::parse($event->getEnd()->getDateTime())->setTimezone($this->timezone($event->getEnd()->getTimeZone(), $timezone));
        }

        $private = $event->getExtendedProperties()?->getPrivate() ?? [];

        return new EventDto(
            uid: $event->getICalUID() ?: $event->getId(),
            recurrenceId: $this->recurrenceId($event),
            title: (string) $event->getSummary(),
            start: $start,
            end: $end,
            allDay: $allDay,
            timezone: $timezone->getName(),
            location: $event->getLocation() ?: null,
            description: $event->getDescription() ?: null,
            status: $event->getStatus() === 'tentative' ? EventStatus::Tentative : EventStatus::Confirmed,
            transparent: $event->getTransparency() === 'transparent',
            markers: array_filter([
                'job' => $private[Markers::GOOGLE_JOB] ?? null,
                'source' => $private[Markers::GOOGLE_SOURCE_UID] ?? null,
                'key' => $private[Markers::GOOGLE_SOURCE_KEY] ?? null,
            ]),
            remoteId: $event->getId(),
            etag: $event->getEtag(),
            declined: collect($event->getAttendees() ?? [])
                ->contains(fn ($attendee) => $attendee->getSelf() && $attendee->getResponseStatus() === 'declined'),
        );
    }

    /**
     * Payload for a copy on a Google calendar: no attendees (Google would send invitations),
     * no reminders (no duplicate notifications), markers in private extended properties.
     */
    public function toGoogle(EventDto $dto): Event
    {
        $event = new Event;
        $event->setSummary($dto->title);
        $event->setLocation($dto->location);
        $event->setDescription($dto->description);
        $event->setStart($this->eventDateTime($dto->start, $dto));
        $event->setEnd($this->eventDateTime($dto->end, $dto));
        $event->setTransparency($dto->transparent ? 'transparent' : 'opaque');
        $event->setStatus($dto->status === EventStatus::Tentative ? 'tentative' : 'confirmed');

        $reminders = new EventReminders;
        $reminders->setUseDefault(false);
        $event->setReminders($reminders);

        $properties = new EventExtendedProperties;
        $properties->setPrivate(array_map(
            fn (string $value) => mb_substr($value, 0, self::MAX_PROPERTY_LENGTH),
            array_filter([
                Markers::GOOGLE_JOB => $dto->markers['job'] ?? null,
                Markers::GOOGLE_SOURCE_UID => $dto->markers['source'] ?? null,
                Markers::GOOGLE_SOURCE_KEY => $dto->markers['key'] ?? null,
            ]),
        ));
        $event->setExtendedProperties($properties);

        return $event;
    }

    /**
     * Whether the event was created by the given job.
     */
    public static function isOwnedBy(Event $event, ?string $jobId): bool
    {
        $private = $event->getExtendedProperties()?->getPrivate() ?? [];

        return $jobId !== null && ($private[Markers::GOOGLE_JOB] ?? null) === $jobId;
    }

    private function eventDateTime(CarbonImmutable $moment, EventDto $dto): EventDateTime
    {
        $value = new EventDateTime;

        if ($dto->allDay) {
            $value->setDate($moment->format('Y-m-d'));
        } else {
            $value->setDateTime($moment->toRfc3339String());
            $value->setTimeZone($this->timezone($dto->timezone, new DateTimeZone('UTC'))->getName());
        }

        return $value;
    }

    /**
     * The original start of a recurring instance, normalized like the CalDAV side.
     */
    private function recurrenceId(Event $event): ?string
    {
        if (blank($event->getRecurringEventId()) || $event->getOriginalStartTime() === null) {
            return null;
        }

        $original = $event->getOriginalStartTime();

        return filled($original->getDate())
            ? CarbonImmutable::parse($original->getDate())->format('Ymd')
            : CarbonImmutable::parse($original->getDateTime())->utc()->format('Ymd\THis\Z');
    }

    private function timezone(?string $name, DateTimeZone $fallback): DateTimeZone
    {
        try {
            return filled($name) ? new DateTimeZone($name) : $fallback;
        } catch (\Exception) {
            return $fallback;
        }
    }
}
