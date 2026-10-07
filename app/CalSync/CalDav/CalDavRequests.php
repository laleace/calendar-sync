<?php

namespace App\CalSync\CalDav;

/**
 * Request bodies for CalDAV discovery.
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
}
