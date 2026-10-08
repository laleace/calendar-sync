<?php

namespace App\CalSync\Contracts;

use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\WrittenEvent;
use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\Models\Calendar;

/**
 * A provider that can be used as a sync target. Every event it writes carries the
 * calendar-sync markers found in EventDto::$markers, so it is never read back as a source.
 */
interface WritableCalendarProvider
{
    /**
     * @throws ProviderException
     */
    public function createEvent(Calendar $calendar, EventDto $event): WrittenEvent;

    /**
     * @throws EventNotFound when the event was deleted from the target.
     * @throws ProviderException
     */
    public function updateEvent(Calendar $calendar, string $remoteId, EventDto $event): WrittenEvent;

    /**
     * Events already gone are ignored.
     *
     * @throws ProviderException
     */
    public function deleteEvent(Calendar $calendar, string $remoteId): void;
}
