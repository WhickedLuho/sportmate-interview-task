<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\GitHubClient;
use App\Integrations\GitHub\RepositoryData;
use App\Models\Repository;
use App\Models\SyncTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepositorySyncService
{
    private const UPSERT_CHUNK = 200;

    /**
     * Create the service with its GitHub client.
     *
     * @param  GitHubClient  $github  Client used to fetch repository pages.
     */
    public function __construct(private readonly GitHubClient $github) {}

    /**
     * Fetch, validate and persist the target's next repository page.
     *
     * @param  SyncTarget  $target  Target whose saved cursor determines the page.
     * @param  string  $runId  Identifier of the repository synchronization run.
     * @param  string  $dispatchId  Identifier of the active queue dispatch.
     *
     * @return bool True if another page is needed; false if finished or skipped.
     *
     * @throws GitHubException
     */
    public function sync(SyncTarget $target, string $runId, string $dispatchId): bool
    {
        $target->refresh();
        if ($target->sync_run_id !== $runId || $target->dispatch_id !== $dispatchId || ! $target->status->isInProgress()) {
            return false;
        }
        if ($target->sync_query_signature !== $this->github->querySignature()) {
            throw new GitHubException('The repository query changed while synchronization was pending.');
        }

        $pageNumber = $target->next_page;
        $guard = fn () => SyncTarget::query()->whereKey($target->id)
            ->where('sync_run_id', $runId)->where('dispatch_id', $dispatchId)->where('next_page', $pageNumber);

        // Syncing is accepted for a reserved job recovered after an ungraceful worker exit.
        if ($guard()->whereIn('status', array_map(fn (SyncStatus $state) => $state->value, SyncStatus::inProgress()))
            ->update(['status' => SyncStatus::Syncing, 'last_attempted_at' => now(), 'retry_at' => null, 'updated_at' => now()]) !== 1) {
            return false;
        }

        // Network I/O never holds a database transaction open.
        $page = $this->github->repositoriesPage($target->name, $pageNumber);
        $saved = DB::transaction(function () use ($guard, $target, $page, $pageNumber, $runId) {
            $now = now();
            $attributes = [
                'status' => $page->hasNextPage ? SyncStatus::Queued : SyncStatus::Synced,
                'next_page' => $page->hasNextPage ? $pageNumber + 1 : 1,
                'last_page_saved_at' => $now, 'retry_at' => null, 'last_error' => null, 'updated_at' => $now,
            ];
            $type = $page->repositories[0]->ownerType ?? null;
            if ($type !== null) {
                $attributes['type'] = $type;
            }
            if (! $page->hasNextPage) {
                $attributes += ['sync_run_id' => null, 'dispatch_id' => null, 'sync_query_signature' => null, 'last_synced_at' => $now];
            }

            // Write the guard first; cursor and repository changes roll back together.
            if ($guard()->where('status', SyncStatus::Syncing->value)->update($attributes) !== 1) {
                return false;
            }
            $this->storePage($target, $page->repositories, $runId);
            if (! $page->hasNextPage) {
                // Existing missing-repository behaviour is preserved, only at full completion.
                $target->repositories()->whereNull('missing_at')
                    ->where(fn ($query) => $query->whereNull('last_seen_sync_run_id')->orWhere('last_seen_sync_run_id', '!=', $runId))
                    ->update(['missing_at' => $now]);
            }

            return true;
        });

        $target->refresh();
        if ($saved) {
            Log::info('GitHub repository page saved.', [
                'sync_target_id' => $target->id, 'target' => $target->name,
                'sync_run_id' => $runId, 'dispatch_id' => $dispatchId,
                'page' => $pageNumber, 'repositories' => count($page->repositories), 'has_next_page' => $page->hasNextPage,
            ]);
            if (! $page->hasNextPage) {
                Log::info('GitHub synchronization finished.', [
                    'sync_target_id' => $target->id, 'target' => $target->name,
                    'repositories' => $target->repositories()->where('last_seen_sync_run_id', $runId)->count(),
                ]);
            }
        }

        return $saved && $page->hasNextPage;
    }

    /**
     * Upsert a validated page and mark its repositories as seen in this run.
     *
     * @param  SyncTarget  $target  Target that owns the repositories.
     * @param  list<RepositoryData>  $repositories  Validated repositories from the fetched page.
     * @param  string  $runId  Run identifier recorded on each saved repository.
     *
     * @return void
     */
    private function storePage(SyncTarget $target, array $repositories, string $runId): void
    {
        $now = now();
        $rows = array_map(fn (RepositoryData $repository) => [
            ...$repository->toAttributes(),
            'sync_target_id' => $target->id, 'last_seen_sync_run_id' => $runId,
            'missing_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ], $repositories);
        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            Repository::query()->upsert($chunk, uniqueBy: ['sync_target_id', 'external_id'], update: [
                'name', 'full_name', 'description', 'html_url', 'language', 'stargazers_count',
                'open_issues_count', 'is_archived', 'external_updated_at', 'missing_at', 'updated_at', 'last_seen_sync_run_id',
            ]);
        }
    }
}
