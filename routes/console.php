<?php

use Illuminate\Support\Facades\Schedule;

// Queues the enabled sync jobs that are due.
Schedule::command('calsync:dispatch-due')->everyMinute()->withoutOverlapping();

// Removes SyncRun rows older than calsync.run_retention_days.
Schedule::command('model:prune')->daily();
