<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\SyncTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);
        $owner = fake()->userName();

        return [
            'sync_target_id' => SyncTarget::factory(),
            'external_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'description' => fake()->optional()->sentence(),
            'html_url' => "https://github.com/{$owner}/{$name}",
            'language' => fake()->optional()->randomElement(['PHP', 'JavaScript', 'TypeScript', 'Go', 'Python']),
            'stargazers_count' => fake()->numberBetween(0, 5000),
            'open_issues_count' => fake()->numberBetween(0, 100),
            'is_archived' => false,
            'external_updated_at' => fake()->dateTimeBetween('-2 years'),
        ];
    }
}
