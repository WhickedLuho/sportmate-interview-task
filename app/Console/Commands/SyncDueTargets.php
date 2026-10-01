<?php

namespace App\Console\Commands;

use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Console\Command;

/**
 * Queues a synchronization for every target that has not been attempted recently.
 *
 * Scheduled hourly (see routes/console.php). Repository data (stars, issues, activity)
 * changes slowly, and an hourly cycle stays far below GitHub's 60 requests/hour limit
 * for unauthenticated use, even with several paginated accounts.
 *
 * It only queues work; the same queue, retry and rate limit handling as a manual
 * synchronization applies.
 */
class SyncDueTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:targets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a synchronization for every target that is due';

    /**
     * A little under the hourly interval. The previous attempt started a few seconds into
     * the last run, so a full 60 minutes would just miss the next tick and delay the
     * target by a whole extra hour.
     */
    public const DUE_AFTER_MINUTES = 55;

    public function handle(): int
    {
        $queued = 0;

        SyncTarget::query()
            ->due(now()->subMinutes(self::DUE_AFTER_MINUTES))
            ->each(function (SyncTarget $target) use (&$queued) {
                // The same atomic claim as the manual button: if the user started a sync
                // between our query and now, nothing is dispatched twice.
                if ($target->markQueued()) {
                    SyncTargetJob::dispatch($target);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} target(s) for synchronization.");

        return self::SUCCESS;
    }
}
