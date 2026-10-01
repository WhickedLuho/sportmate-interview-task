<?php

namespace App\Jobs;

use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\Exceptions\GitHubNotFoundException;
use App\Integrations\GitHub\Exceptions\GitHubRateLimitedException;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Models\SyncTarget;
use App\Services\RepositorySyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs {@see RepositorySyncService} in the background and decides what each kind
 * of failure means for the queue:
 *
 *  - not found / unexpected GitHub answer: permanent, recorded on the target, never retried
 *  - rate limited: put back on the queue until GitHub says the limit resets
 *  - unavailable: rethrown, so the queue retries with back-off
 *  - anything else (a bug): rethrown; ends up in failed_jobs and on the target
 */
class SyncTargetJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Must stay below `retry_after` (90s) of the queue connection. Otherwise a second
     * worker would pick the same job up while the first is still running.
     */
    public int $timeout = 60;

    /** A timeout fails the job outright instead of being retried. */
    public bool $failOnTimeout = true;

    /**
     * Only exceptions count towards this limit; releasing the job because of a rate
     * limit does not use an attempt, so a long rate limit cannot exhaust the retries.
     */
    public int $maxExceptions = 3;

    /** If the target was deleted while the job waited, there is nothing left to do. */
    public bool $deleteWhenMissingModels = true;

    /** Seconds the unique lock is kept at most, so a crashed worker cannot block a target forever. */
    public int $uniqueFor = 7200;

    public function __construct(public SyncTarget $target) {}

    /**
     * One pending/running synchronization per target. A duplicate dispatch while the
     * lock is held is silently dropped.
     */
    public function uniqueId(): string
    {
        return (string) $this->target->id;
    }

    /** Give up for good after this long, however many times the job was released. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Seconds to wait after a thrown exception, per attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RepositorySyncService $sync): void
    {
        try {
            $sync->sync($this->target);
        } catch (GitHubRateLimitedException $e) {
            $this->target->markRateLimited($e->userMessage());

            // A little extra time so we do not wake up exactly at the reset second.
            $this->release(max(1, (int) now()->diffInSeconds($e->retryAt, false)) + 5);
        } catch (GitHubUnavailableException $e) {
            $this->logFailure($e);
            $this->target->markRetrying($e->userMessage());

            throw $e;
        } catch (GitHubNotFoundException $e) {
            // Permanent: retrying cannot make a missing account appear.
            $this->logFailure($e);
            $this->target->markFailed($e->userMessage());
        } catch (GitHubException $e) {
            // e.g. an invalid token. Retrying with the same configuration would fail the same way.
            $this->logFailure($e);
            $this->target->markFailed($e->userMessage());
        }
    }

    /**
     * Called by the queue once the job has failed for good (retries exhausted,
     * timed out, or an unexpected exception). The raw exception is logged; the
     * user only sees a generic message.
     */
    public function failed(Throwable $exception): void
    {
        $this->logFailure($exception);

        $this->target->markFailed(
            $exception instanceof GitHubException
                ? $exception->userMessage()
                : 'The synchronization failed unexpectedly. Please try again later.',
        );
    }

    private function logFailure(Throwable $exception): void
    {
        Log::warning('GitHub synchronization failed.', [
            'sync_target_id' => $this->target->id,
            'target' => $this->target->name,
            'attempt' => $this->attempts(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
