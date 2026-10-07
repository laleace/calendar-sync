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

    'icloud' => [
        'base_url' => 'https://caldav.icloud.com/',
    ],

];
