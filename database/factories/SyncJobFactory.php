<?php

namespace Database\Factories;

use App\Enums\PrivacyMode;
use App\Models\Calendar;
use App\Models\SyncJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncJob>
 */
class SyncJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sync '.fake()->word(),
            'enabled' => true,
            'target_calendar_id' => Calendar::factory(),
            'range_past_days' => 7,
            'range_future_days' => 60,
            'interval_minutes' => 15,
            'privacy_mode' => PrivacyMode::Full,
        ];
    }

    public function busyOnly(): static
    {
        return $this->state(fn () => ['privacy_mode' => PrivacyMode::BusyOnly]);
    }
}
