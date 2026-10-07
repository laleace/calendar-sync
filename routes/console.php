<?php

use Illuminate\Support\Facades\Schedule;

// Removes SyncRun rows older than calsync.run_retention_days.
Schedule::command('model:prune')->daily();
