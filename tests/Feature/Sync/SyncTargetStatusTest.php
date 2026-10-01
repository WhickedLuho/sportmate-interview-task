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

    public function test_finished_targets_can_be_synced_again(): void
    {
        foreach ([SyncStatus::Idle, SyncStatus::Synced, SyncStatus::Failed] as $status) {
            $target = SyncTarget::factory()->create(['status' => $status]);

            $this->assertTrue($target->markQueued(), "{$status->value} must allow a new sync");
            $this->assertSame(SyncStatus::Queued, $target->status);
        }
    }
}
