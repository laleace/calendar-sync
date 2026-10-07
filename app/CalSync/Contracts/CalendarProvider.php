<?php

namespace App\CalSync\Contracts;

use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventList;
use App\CalSync\Exceptions\ProviderException;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;

/**
 * A calendar backend (CalDAV, Google). Implementations never persist anything:
 * callers (AccountService, SyncEngine) own the database writes.
 */
interface CalendarProvider
{
    /**
     * Check that the credentials work. Never throws for provider errors: failures are
     * reported through the result.
     */
    public function testConnection(Account $account): ConnectionResult;

    /**
     * List the event calendars visible to the account.
     *
     * @throws ProviderException
     */
    public function discoverCalendars(Account $account): DiscoveryResult;

    /**
     * Events overlapping [from, to), recurrences expanded into single occurrences.
     * Unreadable events are reported in EventList::$problems, not thrown.
     *
     * @throws ProviderException when the calendar cannot be read at all.
     */
    public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList;
}
