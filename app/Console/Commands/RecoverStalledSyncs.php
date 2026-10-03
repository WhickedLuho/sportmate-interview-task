<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverStalledSyncs extends Command
{
    protected $signature = 'sync:recover';

    protected $description = 'Make pending synchronizations without a queue entry resumable';

    public function handle(): int
    {
        $recovered = 0;
        SyncTarget::query()
            ->whereIn('status', array_map(fn (SyncStatus $status) => $status->value, SyncStatus::inProgress()))
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->each(function (SyncTarget $target) use (&$recovered) {
                // A delayed or reserved job still exists: age alone never proves it is stuck.
                $exists = $target->dispatch_id !== null && DB::connection(config('queue.connections.database.connection'))
                    ->table(config('queue.connections.database.table', 'jobs'))
                    ->where('payload->displayName', (new SyncTargetJob($target))->displayName())->exists();
                if ($exists) {
                    return;
                }

                $changed = SyncTarget::query()->whereKey($target->id)
                    ->where('dispatch_id', $target->dispatch_id)
                    ->whereIn('status', array_map(fn (SyncStatus $status) => $status->value, SyncStatus::inProgress()))
                    ->where('updated_at', '<=', now()->subMinutes(5))
                    ->update([
                        'status' => SyncStatus::Failed,
                        'retry_at' => null,
                        'last_error' => 'The synchronization queue entry is missing. Resume synchronization to continue from the saved page.',
                        'updated_at' => now(),
                    ]);
                if ($changed === 1) {
                    $recovered++;
                    Log::warning('GitHub synchronization queue entry missing.', ['sync_target_id' => $target->id, 'dispatch_id' => $target->dispatch_id]);
                }
            });

        $this->info("Recovered {$recovered} target(s).");

        return self::SUCCESS;
    }
}
