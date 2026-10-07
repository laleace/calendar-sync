<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMapping>
 */
class EventMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uid = fake()->uuid();

        return [
            'sync_job_id' => SyncJob::factory(),
            'source_calendar_id' => Calendar::factory(),
            'source_uid' => $uid,
            'recurrence_id' => null,
            'source_key' => hash('sha256', $uid),
            'target_calendar_id' => fn (array $attributes) => SyncJob::find($attributes['sync_job_id'])->target_calendar_id,
            'target_event_id' => fake()->lexify('????????????????'),
            'content_hash' => hash('sha256', fake()->sentence()),
            'starts_at' => now()->addDay(),
            'last_synced_at' => now(),
        ];
    }
}
