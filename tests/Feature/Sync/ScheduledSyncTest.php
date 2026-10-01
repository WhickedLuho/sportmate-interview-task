<?php

namespace Tests\Feature\Sync;

use App\Console\Commands\SyncDueTargets;
use App\Enums\SyncStatus;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function target(array $attributes = []): SyncTarget
    {
        return SyncTarget::factory()->create($attributes);
    }

    /**
     * @return list<int> ids of the targets a job was dispatched for
     */
    private function queuedTargetIds(): array
    {
        $ids = [];
        Queue::assertPushed(SyncTargetJob::class, function (SyncTargetJob $job) use (&$ids) {
            $ids[] = $job->target->id;

            return true;
        });

        return $ids;
    }

    public function test_it_is_scheduled_hourly(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command, 'sync:targets'));

        $this->assertNotNull($event, 'sync:targets must be registered in the scheduler.');
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_a_target_that_was_never_attempted_is_queued(): void
    {
        Queue::fake();
        $target = $this->target();

        $this->artisan('sync:targets')->expectsOutputToContain('Queued 1 target(s)')->assertSuccessful();

        $this->assertSame([$target->id], $this->queuedTargetIds());
        $this->assertSame(SyncStatus::Queued, $target->refresh()->status);
    }

    public function test_old_synced_and_failed_targets_are_queued_again(): void
    {
        Queue::fake();
        $synced = $this->target(['status' => SyncStatus::Synced, 'last_attempted_at' => now()->subHours(2)]);
        $failed = $this->target(['status' => SyncStatus::Failed, 'last_attempted_at' => now()->subHours(3)]);

        $this->artisan('sync:targets')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$synced->id, $failed->id], $this->queuedTargetIds());
    }

    public function test_recently_attempted_targets_are_left_alone(): void
    {
        Queue::fake();
        $this->target(['status' => SyncStatus::Synced, 'last_attempted_at' => now()->subMinutes(10)]);
        $this->target(['status' => SyncStatus::Failed, 'last_attempted_at' => now()->subMinutes(30)]);

        $this->artisan('sync:targets')->expectsOutputToContain('Queued 0 target(s)')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_hourly_tick_does_not_skip_a_target_that_synced_an_hour_ago(): void
    {
        Queue::fake();
        // Started a few seconds into the previous run, so almost but not quite 60 minutes ago.
        $target = $this->target(['status' => SyncStatus::Synced, 'last_attempted_at' => now()->subMinutes(59)->subSeconds(50)]);

        $this->artisan('sync:targets')->assertSuccessful();

        $this->assertSame([$target->id], $this->queuedTargetIds());
        $this->assertLessThan(60, SyncDueTargets::DUE_AFTER_MINUTES, 'The due threshold must leave slack below the hourly interval.');
    }

    public function test_targets_with_a_pending_or_running_sync_are_never_queued_again(): void
    {
        Queue::fake();

        foreach (SyncStatus::inProgress() as $status) {
            $this->target(['status' => $status, 'last_attempted_at' => now()->subDay()]);
        }

        $this->artisan('sync:targets')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_running_the_command_twice_does_not_queue_a_target_twice(): void
    {
        Queue::fake();
        $this->target();

        $this->artisan('sync:targets')->assertSuccessful();
        $this->artisan('sync:targets')->assertSuccessful();

        Queue::assertPushed(SyncTargetJob::class, 1);
    }
}
