<?php

namespace App\Console\Commands;

use App\CalSync\Sync\SyncService;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('calsync:dispatch-due')]
#[Description('Queue a run for every enabled sync job that is due (run by the scheduler every minute)')]
class DispatchDueSyncJobs extends Command
{
    /** A run still "running" after this long belongs to a worker that died. */
    public const STALE_RUNNING_MINUTES = 30;

    /** A run still queued after this long was never picked up: no worker. */
    public const STALE_QUEUED_HOURS = 6;

    /** Cache key with the time of the last scheduler tick, checked by calsync:doctor. */
    public const HEARTBEAT_KEY = 'calsync:scheduler:last-tick';

    public function handle(SyncService $sync): int
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
        $this->failStaleRuns();

        $due = SyncJob::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->get();

        foreach ($due as $job) {
            $job->update(['next_run_at' => now()->addMinutes($job->interval_minutes)]);

            // Never pile up runs: if the previous one is still waiting or running, skip this turn.
            if ($job->runs()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->exists()) {
                $this->components->warn(__('#:id :name: previous run still in progress, skipping.', ['id' => $job->id, 'name' => $job->name]));

                continue;
            }

            $run = $sync->queue($job, SyncTrigger::Schedule);
            $this->components->info(__('#:id :name: queued run #:run.', ['id' => $job->id, 'name' => $job->name, 'run' => $run->id]));
        }

        return self::SUCCESS;
    }

    private function failStaleRuns(): void
    {
        SyncRun::where('status', SyncRunStatus::Running)
            ->where('started_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->update([
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'message' => __('Interrupted: the worker stopped while running.'),
            ]);

        SyncRun::where('status', SyncRunStatus::Queued)
            ->where('created_at', '<', now()->subHours(self::STALE_QUEUED_HOURS))
            ->update([
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'message' => __('Never run: the queue worker is not running.'),
            ]);
    }
}
