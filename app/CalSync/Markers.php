<?php

namespace App\CalSync;

/**
 * Markers written on every event created by a sync job. Events carrying any of them are
 * never used as a sync source, which prevents loops (A → B → A) and chains.
 */
final class Markers
{
    // CalDAV: custom iCalendar properties on the VEVENT.
    public const CALDAV_JOB = 'X-CALSYNC-JOB';

    public const CALDAV_SOURCE = 'X-CALSYNC-SOURCE';

    // CalDAV: redundant marker in the UID, in case a server drops X- properties.
    public const UID_PREFIX = 'calsync-';

    public const UID_SUFFIX = '@calendar-sync';

    // Google: extendedProperties.private keys.
    public const GOOGLE_JOB = 'calsync_job_id';

    public const GOOGLE_SOURCE_UID = 'calsync_source_uid';

    public const GOOGLE_SOURCE_KEY = 'calsync_source_key';

    /**
     * Deterministic Google event id for a job copy. Google ids use base32hex ([a-v0-9]):
     * a hex sha256 fits. Re-creating after a crash hits a 409 instead of a duplicate.
     */
    public static function googleEventId(string $jobId, string $sourceKey): string
    {
        return hash('sha256', "calsync:{$jobId}:{$sourceKey}");
    }

    public static function isMarkerUid(string $uid): bool
    {
        return str_starts_with($uid, self::UID_PREFIX) && str_ends_with($uid, self::UID_SUFFIX);
    }
}
