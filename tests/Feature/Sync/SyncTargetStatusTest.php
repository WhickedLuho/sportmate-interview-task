<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Models\SyncTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncTargetStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_claim_is_refused_while_the_first_is_pending(): void
    {
        $target = SyncTarget::factory()->create();
        $sameRow = SyncTarget::query()->findOrFail($target->id);

        $this->assertTrue($target->markQueued());
        $this->assertFalse($sameRow->markQueued(), 'A stale copy of the model must not win a second claim.');
        $this->assertSame(SyncStatus::Queued, $sameRow->status);
    }

    public function test_every_in_progress_status_refuses_a_new_claim(): void
    {
        foreach (SyncStatus::inProgress() as $status) {
            $target = SyncTarget::factory()->create(['status' => $status]);

            $this->assertFalse($target->markQueued(), "{$status->value} must refuse a new sync");
            $this->assertFalse($status->canStartSync());
        }
    }

    public function test_only_waiting_synchronizations_can_be_cancelled(): void
    {
        foreach ([SyncStatus::Queued, SyncStatus::RateLimited] as $status) {
            $target = SyncTarget::factory()->create([
                'status' => $status,
                'retry_at' => now()->addMinutes(30),
                'last_error' => 'GitHub rate limit reached.',
            ]);

            $this->assertTrue($target->markCancelled(), "{$status->value} can be cancelled");
            $this->assertSame(SyncStatus::Idle, $target->status);
            $this->assertNull($target->retry_at);
            $this->assertNull($target->last_error, 'Cancelling is not an error.');
        }

        foreach ([SyncStatus::Idle, SyncStatus::Syncing, SyncStatus::Synced, SyncStatus::Failed] as $status) {
            $target = SyncTarget::factory()->create(['status' => $status]);

            $this->assertFalse($target->markCancelled(), "{$status->value} cannot be cancelled");
            $this->assertSame($status, $target->status);
        }
    }

    public function test_a_cancelled_target_can_be_synced_again(): void
    {
        $target = SyncTarget::factory()->create(['status' => SyncStatus::RateLimited]);
        $target->markCancelled();

        $this->assertTrue($target->markQueued());
    }

    public function test_retry_time_is_only_kept_while_rate_limited(): void
    {
        $target = SyncTarget::factory()->create();

        $target->markRateLimited('limited', now()->addMinutes(20));
        $this->assertNotNull($target->retry_at);

        $target->markSyncing();
        $this->assertNull($target->retry_at);

        $target->markRateLimited('limited', now()->addMinutes(20));
        $target->markFailed('boom');
        $this->assertNull($target->retry_at);
    }

    public function test_finished_targets_can_be_synced_again(): void
    {
        foreach ([SyncStatus::Idle, SyncStatus::Synced, SyncStatus::Failed] as $status) {
            $target = SyncTarget::factory()->create(['status' => $status]);

            $this->assertTrue($target->markQueued(), "{$status->value} must allow a new sync");
            $this->assertSame(SyncStatus::Queued, $target->status);
        }
    }

    public function test_mark_synced_clears_progress_so_the_next_request_starts_a_new_run(): void
    {
        $target = SyncTarget::factory()->create(['status' => SyncStatus::Queued, 'next_page' => 13]);
        $oldRun = $target->sync_run_id;
        $target->markSynced(null);
        $this->assertNull($target->sync_run_id);
        $this->assertSame(1, $target->next_page);
        $this->assertTrue($target->markQueued());
        $this->assertNotSame($oldRun, $target->sync_run_id);
        $this->assertSame(1, $target->next_page);
    }
}
