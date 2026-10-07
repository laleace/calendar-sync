<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sync_job_id' => SyncJob::factory(),
            'triggered_by' => SyncTrigger::Manual,
            'status' => SyncRunStatus::Queued,
        ];
    }

    public function finished(SyncRunStatus $status = SyncRunStatus::Success): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}
