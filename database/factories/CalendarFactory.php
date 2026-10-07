<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Calendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'remote_id' => '/123456789/calendars/'.fake()->unique()->uuid().'/',
            'name' => fake()->randomElement(['Casa', 'Lavoro', 'Famiglia', 'Sport']),
            'color' => fake()->hexColor(),
            'timezone' => 'Europe/Rome',
            'can_write' => true,
        ];
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['can_write' => false]);
    }
}
