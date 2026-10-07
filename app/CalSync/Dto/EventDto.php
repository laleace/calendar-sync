<?php

namespace App\CalSync\Dto;

use App\Enums\EventStatus;
use Carbon\CarbonImmutable;

/**
 * A single event instance, normalized across providers. Recurring events are expanded:
 * each occurrence is one EventDto with its own recurrenceId.
 */
final readonly class EventDto
{
    /**
     * @param  string|null  $recurrenceId  Original start of the occurrence, normalized: "20261007T070000Z"
     *                                     (UTC) or "20261007" for all-day. Null for non-recurring events.
     * @param  CarbonImmutable  $start  In the event timezone. All-day: midnight of the first day.
     * @param  CarbonImmutable  $end  Exclusive. All-day: midnight of the day after the last day.
     * @param  string  $timezone  IANA identifier; floating and all-day events use the calendar timezone.
     * @param  array<string, string>  $markers  calendar-sync markers found on the event (job, source, uid).
     * @param  string|null  $remoteId  CalDAV resource URL or Google event id.
     */
    public function __construct(
        public string $uid,
        public ?string $recurrenceId,
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $allDay,
        public string $timezone,
        public ?string $location = null,
        public ?string $description = null,
        public EventStatus $status = EventStatus::Confirmed,
        public bool $transparent = false,
        public array $markers = [],
        public ?string $remoteId = null,
        public ?string $etag = null,
    ) {}

    /**
     * Created by calendar-sync: never a valid sync source.
     */
    public function isMarked(): bool
    {
        return $this->markers !== [];
    }
}
