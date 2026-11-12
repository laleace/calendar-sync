<?php

return [

    /*
    | Timezone used by the GUI and to compute the sync window ("today - X days").
    | Database values are always stored in UTC.
    */
    'timezone' => env('CALSYNC_TIMEZONE', 'Europe/Rome'),

    /*
    | SyncRun rows older than this are pruned by `model:prune`.
    */
    'run_retention_days' => (int) env('CALSYNC_RUN_RETENTION_DAYS', 30),

    /*
    | Log every outgoing CalDAV request (method, URL, status, masked headers) to the
    | default log channel. Authorization and cookies are always masked.
    */
    'http_log' => (bool) env('CALSYNC_HTTP_LOG', false),

    /*
    | Where users of this instance can get the source code (AGPL-3.0 section 13).
    | Point it to your fork if you run a modified version.
    */
    'source_url' => env('CALSYNC_SOURCE_URL', 'https://github.com/laleace/calendar-sync'),

    'icloud' => [
        'base_url' => 'https://caldav.icloud.com/',
    ],

];
