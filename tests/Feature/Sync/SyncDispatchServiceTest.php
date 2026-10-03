<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Models\SyncTarget;
use App\Services\SyncDispatchService;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SyncDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueue_failure_rolls_back_the_target_claim(): void
    {
        $queue = $this->createMock(QueueContract::class);
        $queue->method('push')->willThrowException(new RuntimeException('injected queue failure'));
        Queue::shouldReceive('connection')->with('database')->once()->andReturn($queue);
        $target = SyncTarget::factory()->create();
        try {
            $this->app->make(SyncDispatchService::class)->startOrResume($target);
            $this->fail('Expected enqueue failure.');
        } catch (RuntimeException) {
            $this->assertSame(SyncStatus::Idle, $target->refresh()->status);
            $this->assertNull($target->sync_run_id);
            $this->assertNull($target->dispatch_id);
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_target_and_database_job_are_created_together_without_duplicates(): void
    {
        $target = SyncTarget::factory()->create();
        $dispatch = $this->app->make(SyncDispatchService::class);
        $this->assertTrue($dispatch->startOrResume($target));
        $this->assertFalse($dispatch->startOrResume($target));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($target->sync_run_id);
        $this->assertNotNull($target->dispatch_id);
    }

    public function test_queue_after_commit_setting_cannot_separate_the_two_writes(): void
    {
        config(['queue.connections.database.after_commit' => true]);
        $target = SyncTarget::factory()->create();
        $this->assertTrue($this->app->make(SyncDispatchService::class)->startOrResume($target));
        $this->assertSame(1, DB::table('jobs')->count(), 'The job must exist inside the enclosing test transaction.');
    }
}
