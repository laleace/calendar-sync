<?php

namespace App\Console\Commands;

use App\CalSync\Sync\SyncService;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncJobRunner;
use App\Models\SyncJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('calsync:run
    {job : Sync job id}
    {--dry-run : Show what would change without writing anything}')]
#[Description('Run a sync job now, in the foreground, and print the outcome')]
class RunSyncJob extends Command
{
    public function handle(SyncService $sync): int
    {
        $job = SyncJob::find($this->argument('job'));

        if ($job === null) {
            $this->components->error(__('Job #:id not found.', ['id' => $this->argument('job')]));

            return self::FAILURE;
        }

        $this->components->info(__(':kind of job #:id · :name', ['kind' => $this->option('dry-run') ? 'Dry-run' : 'Sync', 'id' => $job->id, 'name' => $job->name]));

        if ($this->option('dry-run')) {
            $run = $sync->dryRun($job);
        } else {
            // Same lock as the queue worker: never two syncs of the same job at once.
            $lock = Cache::lock(SyncJobRunner::lockName($job->id), 900);

            if (! $lock->get()) {
                $this->components->error(__('Another sync of this job is already running.'));

                return self::FAILURE;
            }

            try {
                $run = $sync->run($job);
            } finally {
                $lock->release();
            }
        }

        [$created, $updated, $deleted] = $run->dry_run
            ? [__('Copies to create'), __('Copies to update'), __('Copies to delete')]
            : [__('Copies created'), __('Copies updated'), __('Copies deleted')];

        $this->components->twoColumnDetail(__('Outcome'), $run->status->getLabel());
        $this->components->twoColumnDetail($created, (string) $run->created_count);
        $this->components->twoColumnDetail($updated, (string) $run->updated_count);
        $this->components->twoColumnDetail($deleted, (string) $run->deleted_count);
        $this->components->twoColumnDetail(__('Events skipped'), (string) $run->skipped_count);
        $this->components->twoColumnDetail(__('Notes'), (string) $run->message);

        foreach ($run->errors ?? [] as $error) {
            $this->components->warn(trim(($error['title'] ?? '').' '.$error['message']));
        }

        return $run->status === SyncRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
