<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncTarget>
 */
class SyncTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->userName(),
            'status' => SyncStatus::Idle,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn () => [
            'status' => SyncStatus::Synced,
            'last_attempted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function failed(string $error = 'GitHub could not be reached.'): static
    {
        return $this->state(fn () => [
            'status' => SyncStatus::Failed,
            'last_attempted_at' => now(),
            'last_error' => $error,
        ]);
    }
}
