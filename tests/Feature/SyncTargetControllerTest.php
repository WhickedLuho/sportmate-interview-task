<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Jobs\SyncTargetJob;
use App\Models\Repository;
use App\Models\SyncTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncTargetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $target = SyncTarget::factory()->create();

        $this->get(route('targets.index'))->assertRedirect(route('login'));
        $this->post(route('targets.store'), ['name' => 'laravel'])->assertRedirect(route('login'));
        $this->post(route('targets.sync', $target))->assertRedirect(route('login'));
    }

    public function test_the_index_lists_only_my_targets_with_repository_counts(): void
    {
        $user = User::factory()->create();
        $mine = SyncTarget::factory()->for($user)->synced()->create(['name' => 'laravel']);
        Repository::factory()->count(3)->for($mine)->create();
        Repository::factory()->for($mine)->create(['missing_at' => now()]);
        SyncTarget::factory()->create(['name' => 'someone-elses']);

        $this->actingAs($user)->get(route('targets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('targets/Index')
                ->has('targets', 1)
                ->where('targets.0.name', 'laravel')
                ->where('targets.0.status', 'synced')
                ->where('targets.0.repositories_count', 3)
                ->where('targets.0.missing_repositories_count', 1)
                ->where('targets.0.can_sync', true));
    }

    public function test_a_target_can_be_created_and_the_name_is_normalized(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('targets.store'), ['name' => '  @Laravel '])
            ->assertRedirect(route('targets.index'));

        $target = $user->syncTargets()->sole();
        $this->assertSame('laravel', $target->name);
        $this->assertSame(SyncStatus::Idle, $target->status);
    }

    public function test_invalid_github_names_are_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['', 'has space', '-leading', 'trailing-', 'double--hyphen', 'under_score', str_repeat('a', 40), 'a/b'] as $name) {
            $this->actingAs($user)->post(route('targets.store'), ['name' => $name])
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(0, SyncTarget::query()->count());
    }

    public function test_valid_edge_case_names_are_accepted(): void
    {
        $user = User::factory()->create();

        foreach (['a', 'a-b', str_repeat('a', 39), 'user123'] as $name) {
            $this->actingAs($user)->post(route('targets.store'), ['name' => $name])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, $user->syncTargets()->count());
    }

    public function test_the_same_account_cannot_be_added_twice_regardless_of_case(): void
    {
        $user = User::factory()->create();
        SyncTarget::factory()->for($user)->create(['name' => 'laravel']);

        $this->actingAs($user)->post(route('targets.store'), ['name' => 'Laravel'])
            ->assertSessionHasErrors(['name' => 'You are already tracking this GitHub account.']);

        $this->assertSame(1, $user->syncTargets()->count());
    }

    public function test_another_user_may_track_the_same_account(): void
    {
        SyncTarget::factory()->create(['name' => 'laravel']);
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('targets.store'), ['name' => 'laravel'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $other->syncTargets()->count());
    }

    public function test_requesting_a_sync_queues_one_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $target = SyncTarget::factory()->for($user)->create();

        $this->actingAs($user)->post(route('targets.sync', $target))
            ->assertRedirect(route('targets.index'));

        Queue::assertPushed(SyncTargetJob::class, fn (SyncTargetJob $job) => $job->target->is($target));
        $this->assertSame(SyncStatus::Queued, $target->refresh()->status);
    }

    public function test_a_second_request_while_queued_does_not_dispatch_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $target = SyncTarget::factory()->for($user)->create();

        $this->actingAs($user)->post(route('targets.sync', $target));
        $this->actingAs($user)->post(route('targets.sync', $target));

        Queue::assertPushed(SyncTargetJob::class, 1);
    }

    public function test_no_job_is_dispatched_while_a_sync_is_in_progress(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        foreach (SyncStatus::inProgress() as $status) {
            $target = SyncTarget::factory()->for($user)->create(['status' => $status]);

            $this->actingAs($user)->post(route('targets.sync', $target))->assertRedirect();
        }

        Queue::assertNothingPushed();
    }

    public function test_a_failed_target_can_be_synced_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $target = SyncTarget::factory()->for($user)->failed()->create();

        $this->actingAs($user)->post(route('targets.sync', $target));

        Queue::assertPushed(SyncTargetJob::class);
        $this->assertSame(SyncStatus::Queued, $target->refresh()->status);
    }

    public function test_someone_elses_target_cannot_be_synced_and_looks_like_it_does_not_exist(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('targets.sync', $target))
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame(SyncStatus::Idle, $target->refresh()->status);
    }
}
