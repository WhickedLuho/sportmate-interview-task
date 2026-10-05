<?php

namespace App\Console\Commands;

use App\Models\SyncTarget;
use App\Services\SyncDispatchService;
use Illuminate\Console\Command;

/**
 * Queues a synchronization for every target that has not been attempted recently.
 *
 * Scheduled hourly (see routes/console.php). Repository data (stars, issues, activity)
 * changes slowly. Quota usage still depends on the total pages across targets;
 * large accounts require a token even with an hourly cycle.
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

    /**
     * Queue eligible targets through the same dispatcher used by manual syncs.
     *
     * @param  SyncDispatchService  $dispatch  Service that atomically claims and queues each target.
     *
     * @return int Command exit code; zero on successful completion.
     */
    public function handle(SyncDispatchService $dispatch): int
    {
        $queued = 0;

        SyncTarget::query()
            ->due(now()->subMinutes(self::DUE_AFTER_MINUTES))
            ->each(function (SyncTarget $target) use (&$queued, $dispatch) {
                // The same atomic claim as the manual button: if the user started a sync
                // between our query and now, nothing is dispatched twice.
                if ($dispatch->startOrResume($target)) {
                    $queued++;
                }
            });

        $this->info("Queued {$queued} target(s) for synchronization.");

        return self::SUCCESS;
    }
}
