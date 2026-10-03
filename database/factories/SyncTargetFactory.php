<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Integrations\GitHub\GitHubClient;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncTarget>
 */
class SyncTargetFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (SyncTarget $target) {
            if ($target->status->isInProgress()) {
                $target->sync_run_id ??= (string) Str::uuid();
                $target->dispatch_id ??= (string) Str::uuid();
                $target->sync_query_signature ??= app(GitHubClient::class)->querySignature();
            }
        });
    }

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
