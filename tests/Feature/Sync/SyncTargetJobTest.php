<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncStatus;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use App\Services\RepositorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class SyncTargetJobTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    private function runJob(SyncTarget $target): SyncTargetJob
    {
        $job = (new SyncTargetJob($target))->withFakeQueueInteractions();
        $job->handle($this->app->make(RepositorySyncService::class));

        return $job;
    }

    public function test_a_successful_run_stores_repositories_and_marks_the_target_synced(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);
        $this->fakeGitHubRepositories([$this->githubRepository()]);

        $job = $this->runJob($target);

        $job->assertNotReleased()->assertNotFailed();
        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
        $this->assertSame(1, $target->repositories()->count());
    }

    public function test_an_unknown_account_fails_permanently_with_a_friendly_message(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $job = $this->runJob($target);

        $job->assertNotReleased();
        $target->refresh();
        $this->assertSame(SyncStatus::Failed, $target->status);
        $this->assertSame('This GitHub user or organization was not found.', $target->last_error);
        $this->assertStringNotContainsString('404', $target->last_error);
    }

    public function test_a_rate_limit_releases_the_job_and_marks_the_target(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response([], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) now()->addMinutes(10)->timestamp,
        ])]);

        $job = $this->runJob($target);

        $job->assertReleased();
        $target->refresh();
        $this->assertSame(SyncStatus::RateLimited, $target->status);
        $this->assertStringContainsString('rate limit', $target->last_error);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp + 5, $target->retry_at->timestamp, 3, 'The shown retry time matches when the job wakes up.');
    }

    public function test_a_job_that_wakes_up_after_the_user_stopped_it_does_nothing(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::RateLimited]);
        $target->markCancelled();
        Http::preventStrayRequests();
        Http::fake();

        $job = $this->runJob($target->refresh());

        $job->assertNotReleased()->assertNotFailed();
        Http::assertNothingSent();
        $this->assertSame(SyncStatus::Idle, $target->refresh()->status);
        $this->assertSame(0, $target->repositories()->count());
    }

    public function test_syncing_again_after_stopping_is_picked_up_by_the_job_that_is_still_waiting(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create(['name' => 'laravel']);

        // First request: the job is queued (and holds the unique lock), then the user stops it.
        $this->assertTrue($target->markQueued());
        SyncTargetJob::dispatch($target);
        $this->assertTrue($target->markCancelled());

        // The user changes their mind before the waiting job wakes up. The dispatch is dropped
        // by the unique lock, so the target must not be left "queued" without a job...
        $this->assertTrue($target->markQueued());
        SyncTargetJob::dispatch($target);
        Queue::assertPushed(SyncTargetJob::class, 1);

        // ...because the job that is still waiting finds the target pending again and does the work.
        $this->fakeGitHubRepositories([$this->githubRepository()]);
        $this->runJob($target->refresh());

        $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
        $this->assertSame(1, $target->repositories()->count());
    }

    public function test_a_transient_failure_is_rethrown_so_the_queue_retries(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Syncing]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response('Bad gateway', 502)]);

        try {
            $this->runJob($target);
            $this->fail('Expected the exception to be rethrown for the queue to retry.');
        } catch (GitHubUnavailableException) {
            // expected
        }

        $target->refresh();
        $this->assertSame(SyncStatus::Queued, $target->status, 'A retry is pending, so the target is queued again.');
        $this->assertStringContainsString('unavailable', $target->last_error);
    }

    public function test_an_unexpected_github_answer_is_not_retried(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->runJob($target)->assertNotReleased();

        $this->assertSame(SyncStatus::Failed, $target->refresh()->status);
        $this->assertStringNotContainsString('Bad credentials', $target->last_error, 'Raw GitHub messages stay in the log.');
    }

    public function test_when_the_queue_gives_up_the_target_is_marked_failed(): void
    {
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);

        (new SyncTargetJob($target))->failed(new RuntimeException('SQLSTATE[HY000]: secret internals'));

        $target->refresh();
        $this->assertSame(SyncStatus::Failed, $target->status);
        $this->assertStringNotContainsString('SQLSTATE', $target->last_error);
    }

    public function test_dispatching_twice_for_the_same_target_queues_only_one_job(): void
    {
        Queue::fake();
        $target = SyncTarget::factory()->create();

        SyncTargetJob::dispatch($target);
        SyncTargetJob::dispatch($target);

        Queue::assertPushed(SyncTargetJob::class, 1);
    }

    public function test_different_targets_can_be_queued_at_the_same_time(): void
    {
        Queue::fake();

        SyncTargetJob::dispatch(SyncTarget::factory()->create());
        SyncTargetJob::dispatch(SyncTarget::factory()->create());

        Queue::assertPushed(SyncTargetJob::class, 2);
    }

    /** @return array<string, array{int, bool}> */
    public static function retryBoundaries(): array
    {
        return [
            'one second before deadline including buffer' => [594, true],
            'exactly at deadline including buffer' => [595, false],
            'one second after deadline' => [596, false],
            'reset before deadline but buffer exceeds it' => [598, false],
            'within a fresh window but beyond original deadline' => [3600, false],
        ];
    }

    #[DataProvider('retryBoundaries')]
    public function test_rate_limit_release_respects_the_original_queue_payload_deadline(int $resetDelay, bool $shouldRelease): void
    {
        $this->freezeTime();
        $target = SyncTarget::factory()->create(['name' => 'laravel', 'status' => SyncStatus::Queued]);
        $queue = Queue::connection('database');
        $queue->push(new SyncTargetJob($target));
        $deadline = now()->addHours(2)->timestamp;
        $this->travel(110)->minutes();
        $this->fakeGitHub(Http::response([], 429, ['Retry-After' => (string) $resetDelay]));

        $queuedJob = $queue->pop();
        $this->assertNotNull($queuedJob);
        $this->assertSame($deadline, $queuedJob->retryUntil());
        $queuedJob->fire();

        $target->refresh();
        $this->assertSame($shouldRelease, $queuedJob->isReleased());
        if ($shouldRelease) {
            $this->assertSame(SyncStatus::RateLimited, $target->status);
            $this->assertSame(now()->addSeconds($resetDelay + 5)->timestamp, $target->retry_at->timestamp);
            $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($deadline, $payload['retryUntil'], 'Release preserves the original deadline.');

            $this->travel($resetDelay + 5)->seconds();
            $this->fakeGitHubRepositories([$this->githubRepository()]);
            $queue->pop()->fire();
            $this->assertSame(SyncStatus::Synced, $target->refresh()->status);
        } else {
            $this->assertSame(SyncStatus::Failed, $target->status);
            $this->assertNull($target->retry_at);
            $this->assertTrue($target->status->canStartSync());
            $this->assertStringContainsString('retry window', $target->last_error);
        }
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
