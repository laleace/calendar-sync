<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'iCloud '.fake()->firstName(),
            'type' => AccountType::IcloudCaldav,
            'base_url' => config('calsync.icloud.base_url'),
            'credentials' => [
                'username' => fake()->safeEmail(),
                'password' => 'abcd-efgh-ijkl-mnop',
            ],
            'status' => AccountStatus::Unknown,
        ];
    }

    public function caldavGeneric(string $baseUrl = 'https://dav.example.test/'): static
    {
        return $this->state(fn () => [
            'name' => 'CalDAV '.fake()->firstName(),
            'type' => AccountType::CaldavGeneric,
            'base_url' => $baseUrl,
        ]);
    }

    public function google(): static
    {
        return $this->state(fn () => [
            'name' => 'Google '.fake()->firstName(),
            'type' => AccountType::Google,
            'base_url' => null,
            'credentials' => [
                'email' => fake()->safeEmail(),
                'access_token' => 'ya29.test-access-token',
                'refresh_token' => '1//test-refresh-token',
                'expires_at' => now()->addHour()->getTimestamp(),
            ],
        ]);
    }
}
