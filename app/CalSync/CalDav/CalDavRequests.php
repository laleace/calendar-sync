<?php

namespace App\CalSync\CalDav;

use DateTimeInterface;
use DateTimeZone;

/**
 * Request bodies for CalDAV discovery and queries.
 */
final class CalDavRequests
{
    public const CURRENT_USER_PRINCIPAL = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop>
            <d:current-user-principal/>
          </d:prop>
        </d:propfind>
        XML;

    public const CALENDAR_HOME_SET = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
          <d:prop>
            <c:calendar-home-set/>
          </d:prop>
        </d:propfind>
        XML;

    public const CALENDAR_COLLECTIONS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
            <c:supported-calendar-component-set/>
            <c:calendar-timezone/>
            <a:calendar-color/>
          </d:prop>
        </d:propfind>
        XML;

    /**
     * Events with at least one occurrence overlapping [from, to). The server evaluates
     * recurrences for the filter; expansion happens client-side (ICalendarMapper).
     */
    public static function calendarQuery(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $utc = new DateTimeZone('UTC');
        $start = (clone $from)->setTimezone($utc)->format('Ymd\THis\Z');
        $end = (clone $to)->setTimezone($utc)->format('Ymd\THis\Z');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop>
                <d:getetag/>
                <c:calendar-data/>
              </d:prop>
              <c:filter>
                <c:comp-filter name="VCALENDAR">
                  <c:comp-filter name="VEVENT">
                    <c:time-range start="{$start}" end="{$end}"/>
                  </c:comp-filter>
                </c:comp-filter>
              </c:filter>
            </c:calendar-query>
            XML;
    }
}
