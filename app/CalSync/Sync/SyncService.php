<?php

namespace App\CalSync\Sync;

use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Support\SecretRedactor;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Illuminate\Support\Str;

/**
 * Runs a sync job and records the outcome as a SyncRun (dry-run or real).
 */
final class SyncService
{
    private const MAX_ERRORS = 100;

    private const MAX_PLAN_ENTRIES = 500;

    public function __construct(private readonly SyncEngine $engine) {}

    public function dryRun(SyncJob $job, SyncTrigger $trigger = SyncTrigger::Manual): SyncRun
    {
        return $this->execute($job->runs()->create(['triggered_by' => $trigger, 'dry_run' => true]));
    }

    public function run(SyncJob $job, SyncTrigger $trigger = SyncTrigger::Manual): SyncRun
    {
        return $this->execute($job->runs()->create(['triggered_by' => $trigger, 'dry_run' => false]));
    }

    /**
     * Execute a queued run: plan, then apply unless it is a dry-run.
     */
    public function execute(SyncRun $run): SyncRun
    {
        $job = $run->syncJob;
        $run->update(['status' => SyncRunStatus::Running, 'started_at' => now()]);

        try {
            $plan = $this->engine->plan($job);
        } catch (ProviderException $e) {
            return $this->finish($run, SyncRunStatus::Failed, ['message' => $this->clean($e->getMessage())]);
        }

        $readErrors = array_map(fn (string $problem) => ['op' => 'read', 'source' => null, 'uid' => null, 'title' => null, 'message' => $this->clean($problem)], $plan->problems);
        $base = ['skipped_count' => count($plan->skipped)];

        if ($run->dry_run) {
            return $this->finish($run, $plan->problems === [] ? SyncRunStatus::Success : SyncRunStatus::Partial, [
                ...$base,
                'created_count' => $plan->count(OperationType::Create),
                'updated_count' => $plan->count(OperationType::Update),
                'deleted_count' => $plan->count(OperationType::Delete),
                'error_count' => count($readErrors),
                'errors' => $readErrors ?: null,
                'plan' => $plan->preview(self::MAX_PLAN_ENTRIES),
                'message' => sprintf('%d copie già allineate.', $plan->unchanged),
            ]);
        }

        $result = $this->engine->apply($job, $plan);
        $errors = [...$readErrors, ...array_map(fn (array $error) => [...$error, 'message' => $this->clean($error['message'])], $result->errors)];

        $succeeded = $result->created + $result->updated + $result->deleted;

        $status = match (true) {
            // Nothing written while there was something to write.
            $succeeded === 0 && ($result->fatal !== null || $result->errors !== []) => SyncRunStatus::Failed,
            $errors !== [] => SyncRunStatus::Partial,
            default => SyncRunStatus::Success,
        };

        $job->forceFill(['last_run_at' => now()])->save();

        return $this->finish($run, $status, [
            ...$base,
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'deleted_count' => $result->deleted,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, self::MAX_ERRORS) ?: null,
            'message' => $result->fatal !== null
                ? 'Interrotto: '.$this->clean($result->fatal)
                : sprintf('%d copie già allineate.', $plan->unchanged),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(SyncRun $run, SyncRunStatus $status, array $attributes): SyncRun
    {
        $run->update([...$attributes, 'status' => $status, 'finished_at' => now()]);

        return $run;
    }

    private function clean(string $message): string
    {
        return Str::limit(SecretRedactor::redact($message), 490);
    }
}
