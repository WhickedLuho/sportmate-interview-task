<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Repository;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_name_is_stored_lowercased_and_trimmed(): void
    {
        $target = SyncTarget::factory()->create(['name' => '  LaRaVeL ']);

        $this->assertSame('laravel', $target->fresh()->name);
        $this->assertSame(SyncStatus::Idle, $target->fresh()->status);
    }

    public function test_a_user_cannot_have_the_same_target_twice(): void
    {
        $user = User::factory()->create();
        SyncTarget::factory()->for($user)->create(['name' => 'laravel']);

        $this->expectException(QueryException::class);

        SyncTarget::factory()->for($user)->create(['name' => 'Laravel']);
    }

    public function test_different_users_can_track_the_same_target(): void
    {
        SyncTarget::factory()->create(['name' => 'laravel']);
        SyncTarget::factory()->create(['name' => 'laravel']);

        $this->assertSame(2, SyncTarget::query()->where('name', 'laravel')->count());
    }

    public function test_a_repository_is_stored_once_per_target(): void
    {
        $target = SyncTarget::factory()->create();
        Repository::factory()->for($target)->create(['external_id' => 42]);

        $this->expectException(QueryException::class);

        Repository::factory()->for($target)->create(['external_id' => 42]);
    }

    public function test_deleting_a_target_deletes_its_repositories(): void
    {
        $target = SyncTarget::factory()->create();
        Repository::factory()->count(3)->for($target)->create();

        $target->delete();

        $this->assertSame(0, Repository::query()->count());
    }
}
