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
            $this->components->error("Job #{$this->argument('job')} non trovato.");

            return self::FAILURE;
        }

        $this->components->info(($this->option('dry-run') ? 'Dry-run' : 'Sync')." del job #{$job->id} · {$job->name}");

        if ($this->option('dry-run')) {
            $run = $sync->dryRun($job);
        } else {
            // Same lock as the queue worker: never two syncs of the same job at once.
            $lock = Cache::lock(SyncJobRunner::lockName($job->id), 900);

            if (! $lock->get()) {
                $this->components->error('Un altro sync di questo job è già in esecuzione.');

                return self::FAILURE;
            }

            try {
                $run = $sync->run($job);
            } finally {
                $lock->release();
            }
        }

        [$created, $updated, $deleted] = $run->dry_run
            ? ['Copie da creare', 'Copie da aggiornare', 'Copie da eliminare']
            : ['Copie create', 'Copie aggiornate', 'Copie eliminate'];

        $this->components->twoColumnDetail('Esito', $run->status->getLabel());
        $this->components->twoColumnDetail($created, (string) $run->created_count);
        $this->components->twoColumnDetail($updated, (string) $run->updated_count);
        $this->components->twoColumnDetail($deleted, (string) $run->deleted_count);
        $this->components->twoColumnDetail('Eventi saltati', (string) $run->skipped_count);
        $this->components->twoColumnDetail('Note', (string) $run->message);

        foreach ($run->errors ?? [] as $error) {
            $this->components->warn(trim(($error['title'] ?? '').' '.$error['message']));
        }

        return $run->status === SyncRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
