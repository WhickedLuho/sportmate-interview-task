<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Jobs\SyncTargetJob;
use App\Models\Repository;
use App\Models\SyncTarget;
use App\Services\RepositorySyncService;
use App\Services\SyncDispatchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class ResumableSyncTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private const NEXT = ['Link' => '<https://api.github.com/x?page=2>; rel="next"'];

    private function start(SyncTarget $target): void
    {
        $this->assertTrue($this->app->make(SyncDispatchService::class)->startOrResume($target));
    }

    private function runPage(SyncTarget $target): SyncTargetJob
    {
        $job = (new SyncTargetJob($target->refresh()))->withFakeQueueInteractions();
        $job->handle($this->app->make(RepositorySyncService::class));

        return $job;
    }

    /** @return array<string, array{int}> */
    public static function repositoryCounts(): array
    {
        return array_combine(
            array_map(fn (int $n) => "{$n} repositories", [0, 1, 99, 100, 101, 199, 200, 201, 1000, 8400]),
            array_map(fn (int $n) => [$n], [0, 1, 99, 100, 101, 199, 200, 201, 1000, 8400]),
        );
    }

    #[DataProvider('repositoryCounts')]
    public function test_pages_are_committed_individually_and_only_the_last_page_finishes(int $count): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $runId = $target->sync_run_id;
        $pages = max(1, (int) ceil($count / 100));
        $this->fakeGitHub(function (Request $request) use ($count, $pages) {
            $page = (int) $request['page'];
            $first = ($page - 1) * 100 + 1;
            $last = min($page * 100, $count);
            $rows = $first > $last ? [] : array_map(fn (int $id) => $this->githubRepository($id, "repo-{$id}"), range($first, $last));

            return Http::response($rows, 200, $page < $pages ? self::NEXT : []);
        });

        for ($page = 1; $page <= $pages; $page++) {
            $job = $this->runPage($target);
            $target->refresh();
            $this->assertSame(min($count, $page * 100), $target->repositories()->count());
            $this->assertNotNull($target->last_page_saved_at);
            if ($page < $pages) {
                $job->assertReleased(1);
                $this->assertSame($page + 1, $target->next_page);
                $this->assertSame($runId, $target->sync_run_id);
                $this->assertNull($target->last_synced_at);
            } else {
                $job->assertNotReleased();
                $this->assertSame(SyncStatus::Synced, $target->status);
                $this->assertNull($target->sync_run_id);
                $this->assertNotNull($target->last_synced_at);
            }
        }
        Http::assertSentCount($pages);
    }

    public function test_a_second_page_failure_and_manual_resume_skip_the_saved_page(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $runId = $target->sync_run_id;
        $this->fakeGitHub(Http::sequence()->push([$this->githubRepository(1)], 200, self::NEXT)->push('Bad gateway', 502));
        $this->runPage($target)->assertReleased(1);
        $failedJob = new SyncTargetJob($target);
        try {
            $this->runPage($target);
            $this->fail('Expected transient failure.');
        } catch (GitHubUnavailableException $e) {
            $failedJob->failed($e);
        }
        $this->assertSame(2, $target->refresh()->next_page);
        $this->assertSame(1, $target->repositories()->count());
        $this->start($target);
        $this->assertSame($runId, $target->sync_run_id);
        $this->fakeGitHubRepositories([$this->githubRepository(2, 'docs')]);
        $this->runPage($target)->assertNotReleased();
        Http::assertSent(fn (Request $request) => $request['page'] === 2);
        Http::assertSentCount(1);
        $this->assertSame(2, $target->repositories()->count());
        $this->assertSame(0, $target->repositories()->whereNotNull('missing_at')->count());
    }

    public function test_a_database_failure_rolls_back_both_the_page_and_cursor(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $this->fakeGitHubRepositories([$this->githubRepository()]);
        DB::statement("CREATE TRIGGER reject_page BEFORE INSERT ON repositories BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");
        try {
            $this->runPage($target);
            $this->fail('Expected database failure.');
        } catch (QueryException) {
            $this->assertSame(1, $target->refresh()->next_page);
            $this->assertSame(0, $target->repositories()->count());
            $this->assertNull($target->last_synced_at);
            $this->assertNull($target->last_page_saved_at);
        } finally {
            DB::statement('DROP TRIGGER reject_page');
        }
        $this->runPage($target);
        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
    }

    public function test_stop_resume_ignores_old_payloads_and_failure_callbacks(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $this->fakeGitHub(Http::response([$this->githubRepository()], 200, self::NEXT));
        $this->runPage($target);
        $old = new SyncTargetJob($target);
        $run = $target->sync_run_id;
        $this->assertTrue($target->markCancelled());
        $this->start($target);
        $this->assertSame($run, $target->sync_run_id);
        $this->assertNotSame($old->dispatchId, $target->dispatch_id);
        $this->fakeGitHubRepositories([$this->githubRepository(2, 'docs')]);
        $old->withFakeQueueInteractions()->handle($this->app->make(RepositorySyncService::class));
        $old->failed(new RuntimeException('Expired old payload'));
        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Queued, $target->refresh()->status);
        $this->assertSame(2, $target->next_page);
        $this->runPage($target);
        Http::assertSent(fn (Request $request) => $request['page'] === 2);
    }

    public function test_missing_reconciliation_waits_until_all_pages_have_been_saved(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->synced()->create(['name' => 'laravel']);
        $oldSuccess = $target->last_synced_at;
        Repository::factory()->for($target)->create(['external_id' => 1]);
        Repository::factory()->for($target)->create(['external_id' => 2]);
        Repository::factory()->for($target)->create(['external_id' => 3]);
        $this->start($target);
        $this->fakeGitHub(Http::sequence()->push([$this->githubRepository(1)], 200, self::NEXT)->push([$this->githubRepository(2, 'docs')]));
        $this->runPage($target);
        $this->assertSame(0, $target->repositories()->whereNotNull('missing_at')->count());
        $this->assertTrue($target->refresh()->last_synced_at->equalTo($oldSuccess));
        $this->runPage($target);
        $this->assertSame([3], $target->repositories()->whereNotNull('missing_at')->pluck('external_id')->all());
    }

    public function test_superseded_dispatch_cannot_commit_an_in_flight_response(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $job = new SyncTargetJob($target);
        $this->fakeGitHub(function () use ($target) {
            SyncTarget::query()->whereKey($target->id)->update(['dispatch_id' => 'replacement', 'status' => SyncStatus::Queued]);

            return Http::response([$this->githubRepository()]);
        });
        $job->withFakeQueueInteractions()->handle($this->app->make(RepositorySyncService::class));
        $this->assertSame(0, $target->repositories()->count());
        $this->assertSame(1, $target->refresh()->next_page);
        $this->assertSame(SyncStatus::Queued, $target->status);
    }

    public function test_query_change_restarts_at_page_one_without_deleting_saved_rows(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $this->fakeGitHub(Http::response([$this->githubRepository()], 200, self::NEXT));
        $this->runPage($target);
        $oldRun = $target->sync_run_id;
        $target->markCancelled();
        config(['services.github.base_url' => 'https://other.example']);
        $this->start($target);
        $this->assertNotSame($oldRun, $target->sync_run_id);
        $this->assertSame(1, $target->next_page);
        $this->assertSame(1, $target->repositories()->count());
    }

    public function test_database_queue_interleaves_targets_and_preserves_the_original_deadline(): void
    {
        $this->freezeTime();
        $large = SyncTarget::factory()->create(['name' => 'laravel']);
        $small = SyncTarget::factory()->create(['name' => 'other']);
        $this->start($large);
        $this->start($small);
        $queue = Queue::connection('database');
        $deadline = now()->addHours(2)->timestamp;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response([$this->githubRepository()], 200,
            str_contains($request->url(), '/laravel/') && $request['page'] < 4 ? self::NEXT : []));

        $queue->pop()->fire();
        $this->assertSame(2, $large->refresh()->next_page);
        $queue->pop()->fire();
        $this->assertSame(SyncStatus::Synced, $small->refresh()->status);
        for ($page = 2; $page <= 4; $page++) {
            $this->travel(1)->seconds();
            $job = $queue->pop();
            $this->assertNotNull($job);
            $this->assertSame($deadline, $job->retryUntil());
            $job->fire();
        }
        $this->assertSame(SyncStatus::Synced, $large->refresh()->status);
        $this->assertSame(1, $large->repositories()->count(), 'Repeated repository ids must not duplicate records.');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_real_worker_logic_allows_more_than_three_successful_pages(): void
    {
        $this->freezeTime();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $queue = Queue::connection('database');
        $this->fakeGitHub(fn (Request $request) => Http::response([$this->githubRepository((int) $request['page'])], 200, $request['page'] < 5 ? self::NEXT : []));
        $worker = $this->app->make('queue.worker');
        for ($page = 1; $page <= 5; $page++) {
            $job = $queue->pop();
            $this->assertNotNull($job);
            $worker->process('database', $job, new WorkerOptions(maxTries: 3));
            $this->assertFalse($job->hasFailed());
            $this->travel(1)->seconds();
        }
        $this->assertSame(5, $target->repositories()->count());
        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
    }

    public function test_an_expired_old_queue_payload_cannot_fail_a_resumed_dispatch(): void
    {
        $this->freezeTime();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $target->markCancelled();
        $this->travel(130)->minutes();
        $this->start($target);
        $this->fakeGitHubRepositories([$this->githubRepository()]);
        $queue = Queue::connection('database');
        $old = $queue->pop();
        try {
            $this->app->make('queue.worker')->process('database', $old, new WorkerOptions(maxTries: 3));
            $this->fail('Expected the old deadline to fail before handle().');
        } catch (MaxAttemptsExceededException) {
            // The worker invokes failed() before throwing; the new dispatch must survive.
        }
        $this->assertTrue($old->hasFailed());
        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Queued, $target->refresh()->status);
        $queue->pop()->fire();
        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
    }

    public function test_overlap_lock_releases_the_job_without_requesting_github(): void
    {
        $this->freezeTime();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);
        $this->start($target);
        $command = new SyncTargetJob($target);
        $key = $command->middleware()[0]->getLockKey($command);
        $lock = Cache::lock($key, 75);
        $this->assertTrue($lock->get());
        $this->fakeGitHubRepositories([$this->githubRepository()]);
        $queue = Queue::connection('database');
        $job = $queue->pop();
        $job->fire();
        $this->assertTrue($job->isReleased());
        Http::assertNothingSent();
        $this->assertSame(1, $target->refresh()->next_page);
        $this->travel(76)->seconds();
        $queue->pop()->fire();
        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
    }

    public function test_page_limit_fails_safely_and_keeps_saved_repositories_and_cursor(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued, 'next_page' => 101]);
        Repository::factory()->for($target)->create();
        $this->fakeGitHubRepositories([]);
        $this->runPage($target)->assertNotReleased();
        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Failed, $target->refresh()->status);
        $this->assertSame(101, $target->next_page);
        $this->assertSame(1, $target->repositories()->whereNull('missing_at')->count());
        $this->assertNull($target->last_synced_at);
    }
}
