<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\Exceptions\GitHubRateLimitedException;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Models\SyncTarget;
use App\Services\RepositorySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTargetJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** Below overlap lease (75s), below queue retry_after (90s). */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 3;

    public bool $deleteWhenMissingModels = true;

    // Old serialized jobs can exit safely after the progress migration.
    public ?string $runId = null;

    public ?string $dispatchId = null;

    /**
     * Capture the target and the identifiers of its active dispatch.
     *
     * @param  SyncTarget  $target  Target claimed before this job is queued.
     */
    public function __construct(public SyncTarget $target)
    {
        $this->runId = $target->sync_run_id;
        $this->dispatchId = $target->dispatch_id;
    }

    /**
     * Identify the target and dispatch in the queue payload and worker output.
     *
     * @return string Queue job name containing the target and dispatch identifiers.
     */
    public function displayName(): string
    {
        return 'SyncTargetJob:'.$this->target->id.':'.($this->dispatchId ?? 'legacy');
    }

    /**
     * Prevent concurrent jobs from processing the same target.
     *
     * @return list<WithoutOverlapping> Target-level overlap middleware.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-target:'.$this->target->id))->releaseAfter(1)->expireAfter(75)];
    }

    /**
     * Provide the deadline serialized once when the job is queued.
     *
     * @return \DateTimeInterface Retry deadline preserved across page and rate-limit releases.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Use a fixed delay because successful page releases also count as attempts.
     *
     * @return int Seconds to wait before retrying an unhandled failure.
     */
    public function backoff(): int
    {
        return 30;
    }

    /**
     * Process one page and release the job when more work or a rate-limit wait is needed.
     *
     * @param  RepositorySyncService  $sync  Service that fetches and persists the next page.
     *
     * @return void
     *
     * @throws GitHubUnavailableException
     */
    public function handle(RepositorySyncService $sync): void
    {
        $this->target->refresh();
        if (! $this->isCurrent()) {
            Log::info('GitHub synchronization skipped: stopped or superseded dispatch.', [
                'sync_target_id' => $this->target->id, 'dispatch_id' => $this->dispatchId,
            ]);

            return;
        }

        try {
            if ($sync->sync($this->target, $this->runId, $this->dispatchId)) {
                if (! $this->canRetryAt(now()->addSecond())) {
                    $this->recordState(SyncStatus::Failed, 'The synchronization retry window has expired. Resume synchronization to continue from the saved page.');

                    return;
                }
                // Give other targets a chance to run; the cursor lives in the database.
                $this->release(1);
            }
        } catch (GitHubRateLimitedException $e) {
            $delay = max(1, (int) now()->diffInSeconds($e->retryAt, false)) + 5;
            $retryAt = now()->addSeconds($delay);
            if (! $this->canRetryAt($retryAt)) {
                $this->logFailure($e);
                $this->recordState(SyncStatus::Failed, 'GitHub rate limit would exceed the synchronization retry window. Please start a new synchronization later.');

                return;
            }
            if ($this->recordState(SyncStatus::RateLimited, $e->userMessage(), $retryAt)) {
                $this->release($delay);
            }
        } catch (GitHubUnavailableException $e) {
            $this->logFailure($e);
            if ($this->recordState(SyncStatus::Queued, $e->userMessage())) {
                throw $e;
            }
        } catch (GitHubException $e) {
            $this->logFailure($e);
            $this->recordState(SyncStatus::Failed, $e->userMessage());
        }
    }

    /**
     * Record a terminal failure only if this dispatch is still active.
     *
     * @param  Throwable  $exception  Failure reported by the queue worker.
     *
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->logFailure($exception);
        $this->recordState(SyncStatus::Failed, $exception instanceof GitHubException
            ? $exception->userMessage()
            : 'The synchronization failed unexpectedly. Please try again later.');
    }

    /**
     * Check whether this job still belongs to the target's active synchronization.
     *
     * @return bool True if both identifiers match and the target is in progress.
     */
    private function isCurrent(): bool
    {
        return $this->runId !== null && $this->dispatchId !== null
            && $this->target->sync_run_id === $this->runId && $this->target->dispatch_id === $this->dispatchId
            && $this->target->status->isInProgress();
    }

    /**
     * Check a proposed retry time against the original queue deadline.
     *
     * @param  \DateTimeInterface  $retryAt  Proposed time for the next execution.
     *
     * @return bool True if no deadline is set or the retry precedes it.
     */
    private function canRetryAt(\DateTimeInterface $retryAt): bool
    {
        $deadline = $this->job?->retryUntil();

        return $deadline === null || $retryAt->getTimestamp() < $deadline;
    }

    /**
     * Update status and error details only for the still-active dispatch.
     *
     * @param  SyncStatus  $status  Status to record on the target.
     * @param  string  $message  User-safe error message to display.
     * @param  \DateTimeInterface|null  $retryAt  Scheduled retry time, or null to clear it.
     *
     * @return bool True if the active target was updated; false if superseded or stopped.
     */
    private function recordState(SyncStatus $status, string $message, ?\DateTimeInterface $retryAt = null): bool
    {
        if ($this->runId === null || $this->dispatchId === null) {
            return false;
        }

        return SyncTarget::query()->whereKey($this->target->id)
            ->where('sync_run_id', $this->runId)->where('dispatch_id', $this->dispatchId)
            ->whereIn('status', array_map(fn (SyncStatus $state) => $state->value, SyncStatus::inProgress()))
            ->update(['status' => $status, 'last_error' => $message, 'retry_at' => $retryAt, 'updated_at' => now()]) === 1;
    }

    /**
     * Log a failure with target, page and dispatch details for debugging.
     *
     * @param  Throwable  $exception  Exception whose type and technical message are logged.
     *
     * @return void
     */
    private function logFailure(Throwable $exception): void
    {
        Log::warning('GitHub synchronization failed.', [
            'sync_target_id' => $this->target->id, 'target' => $this->target->name,
            'sync_run_id' => $this->runId, 'dispatch_id' => $this->dispatchId,
            'page' => $this->target->next_page, 'attempt' => $this->attempts(),
            'exception' => $exception::class, 'message' => $exception->getMessage(),
        ]);
    }
}
