<?php

namespace App\Services;

use App\Enums\TargetType;
use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\GitHubClient;
use App\Integrations\GitHub\RepositoryData;
use App\Models\Repository;
use App\Models\SyncTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Synchronizes one target's repositories from GitHub into the local database.
 *
 * It owns the happy path and the database writes. It does not decide how
 * failures are retried; GitHub exceptions bubble up to the queued job, which
 * owns that policy.
 */
class RepositorySyncService
{
    /** Rows per upsert statement, to stay well below SQLite's bound-variable limit. */
    private const UPSERT_CHUNK = 200;

    public function __construct(private readonly GitHubClient $github) {}

    /**
     * @throws GitHubException
     */
    public function sync(SyncTarget $target): void
    {
        $target->markSyncing();

        // All network I/O happens before the transaction, so the database
        // transaction stays short and never waits on GitHub.
        $repositories = $this->github->repositories($target->name);

        DB::transaction(function () use ($target, $repositories) {
            $this->store($target, $repositories);

            $target->markSynced($this->detectType($repositories));
        });

        Log::info('GitHub synchronization finished.', [
            'sync_target_id' => $target->id,
            'target' => $target->name,
            'repositories' => count($repositories),
        ]);
    }

    /**
     * @param  list<RepositoryData>  $repositories
     */
    private function store(SyncTarget $target, array $repositories): void
    {
        $now = now();

        $rows = array_map(fn (RepositoryData $repository) => [
            ...$repository->toAttributes(),
            'sync_target_id' => $target->id,
            // Seeing a repository again means it is no longer missing.
            'missing_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $repositories);

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            Repository::query()->upsert(
                $chunk,
                uniqueBy: ['sync_target_id', 'external_id'],
                // created_at is left out on purpose: updates keep the original value.
                update: [
                    'name', 'full_name', 'description', 'html_url', 'language', 'stargazers_count',
                    'open_issues_count', 'is_archived', 'external_updated_at', 'missing_at', 'updated_at',
                ],
            );
        }

        // Reconciliation: repositories GitHub no longer returns are flagged, not deleted,
        // so the action is reversible and local history is kept. The list hides them by default.
        $target->repositories()
            ->whereNull('missing_at')
            ->whereNotIn('external_id', array_map(fn (RepositoryData $repository) => $repository->externalId, $repositories))
            ->update(['missing_at' => $now]);
    }

    /**
     * GitHub tells us whether the account is a user or an organization on each
     * repository's owner, which saves an extra API request. With no repositories
     * the type stays unknown.
     *
     * @param  list<RepositoryData>  $repositories
     */
    private function detectType(array $repositories): ?TargetType
    {
        return $repositories[0]->ownerType ?? null;
    }
}
