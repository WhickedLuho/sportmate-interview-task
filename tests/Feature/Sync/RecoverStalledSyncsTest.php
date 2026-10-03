<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStalledSyncsTest extends TestCase
{
    use RefreshDatabase;

    private function oldTarget(): SyncTarget
    {
        return SyncTarget::factory()->create(['status' => SyncStatus::Queued, 'next_page' => 13, 'updated_at' => now()->subMinutes(10)]);
    }

    public function test_lost_job_becomes_resumable_without_losing_progress(): void
    {
        $target = $this->oldTarget();
        $run = $target->sync_run_id;
        $this->artisan('sync:recover')->expectsOutputToContain('Recovered 1 target(s)')->assertSuccessful();
        $this->assertSame(SyncStatus::Failed, $target->refresh()->status);
        $this->assertSame(13, $target->next_page);
        $this->assertSame($run, $target->sync_run_id);
    }

    public function test_delayed_and_reserved_jobs_are_never_recovered_by_age_alone(): void
    {
        $delayed = $this->oldTarget();
        $reserved = $this->oldTarget();
        $queue = Queue::connection('database');
        $queue->later(now()->addHour(), new SyncTargetJob($delayed));
        $queue->push(new SyncTargetJob($reserved));
        $this->assertNotNull($queue->pop());
        $this->artisan('sync:recover')->expectsOutputToContain('Recovered 0 target(s)')->assertSuccessful();
        $this->assertSame(SyncStatus::Queued, $delayed->refresh()->status);
        $this->assertSame(SyncStatus::Queued, $reserved->refresh()->status);
    }

    public function test_an_old_dispatch_job_does_not_keep_a_new_missing_dispatch_alive(): void
    {
        $target = $this->oldTarget();
        Queue::connection('database')->push(new SyncTargetJob($target));
        SyncTarget::query()->whereKey($target->id)->update(['dispatch_id' => 'new-dispatch', 'updated_at' => now()->subMinutes(10)]);
        $this->artisan('sync:recover')->assertSuccessful();
        $this->assertSame(SyncStatus::Failed, $target->refresh()->status);
    }

    public function test_recent_and_stopped_targets_are_left_alone(): void
    {
        $recent = SyncTarget::factory()->create(['status' => SyncStatus::Queued]);
        $stopped = $this->oldTarget();
        $stopped->markCancelled();
        $this->artisan('sync:recover')->expectsOutputToContain('Recovered 0 target(s)')->assertSuccessful();
        $this->assertSame(SyncStatus::Queued, $recent->refresh()->status);
        $this->assertSame(SyncStatus::Idle, $stopped->refresh()->status);
    }
}
