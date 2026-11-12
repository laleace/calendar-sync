<?php

namespace App\Jobs;

use App\CalSync\Support\SecretRedactor;
use App\CalSync\Sync\SyncService;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes a queued SyncRun. One run per job at a time: a run that finds the job lock
 * taken is marked as skipped.
 */
class SyncJobRunner implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'sync';

    /** Longer than $timeout, so a crashed worker never keeps the lock forever. */
    private const LOCK_SECONDS = 900;

    /** The sync is idempotent: the next scheduled run retries, no retry storms on bad credentials. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $syncRunId)
    {
        $this->onQueue(self::QUEUE);
    }

    public static function lockName(int $syncJobId): string
    {
        return "calsync:job:{$syncJobId}";
    }

    public function handle(SyncService $sync): void
    {
        $run = SyncRun::find($this->syncRunId);

        if ($run === null || $run->status !== SyncRunStatus::Queued) {
            return;
        }

        $lock = Cache::lock(self::lockName($run->sync_job_id), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $run->update([
                'status' => SyncRunStatus::Skipped,
                'finished_at' => now(),
                'message' => __('Another sync of this job is already running.'),
            ]);

            return;
        }

        try {
            $sync->execute($run);
        } catch (Throwable $e) {
            report($e);
            self::markFailed($this->syncRunId, $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the queue when the job dies (e.g. timeout).
     */
    public function failed(?Throwable $e): void
    {
        self::markFailed($this->syncRunId, $e);
    }

    private static function markFailed(int $syncRunId, ?Throwable $e): void
    {
        SyncRun::whereKey($syncRunId)
            ->whereIn('status', [SyncRunStatus::Queued->value, SyncRunStatus::Running->value])
            ->update([
                'status' => SyncRunStatus::Failed->value,
                'finished_at' => now(),
                'message' => Str::limit(__('Unexpected error: :error', ['error' => SecretRedactor::redact($e?->getMessage() ?? __('job terminated'))]), 490),
            ]);
    }
}
