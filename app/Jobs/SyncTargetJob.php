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

    public function __construct(public SyncTarget $target)
    {
        $this->runId = $target->sync_run_id;
        $this->dispatchId = $target->dispatch_id;
    }

    public function displayName(): string
    {
        return 'SyncTargetJob:'.$this->target->id.':'.($this->dispatchId ?? 'legacy');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-target:'.$this->target->id))->releaseAfter(1)->expireAfter(75)];
    }

    /** Captured once in the payload, preserved on every release. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /** Successful pages also count as attempts, so backoff is not attempt-indexed. */
    public function backoff(): int
    {
        return 30;
    }

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

    public function failed(Throwable $exception): void
    {
        $this->logFailure($exception);
        $this->recordState(SyncStatus::Failed, $exception instanceof GitHubException
            ? $exception->userMessage()
            : 'The synchronization failed unexpectedly. Please try again later.');
    }

    private function isCurrent(): bool
    {
        return $this->runId !== null && $this->dispatchId !== null
            && $this->target->sync_run_id === $this->runId && $this->target->dispatch_id === $this->dispatchId
            && $this->target->status->isInProgress();
    }

    private function canRetryAt(\DateTimeInterface $retryAt): bool
    {
        $deadline = $this->job?->retryUntil();

        return $deadline === null || $retryAt->getTimestamp() < $deadline;
    }

    /** Failures can happen before handle(), so the callback must also be fenced. */
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
