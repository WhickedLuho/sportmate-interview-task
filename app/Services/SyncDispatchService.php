<?php

namespace App\Services;

use App\Integrations\GitHub\GitHubClient;
use App\Jobs\SyncTargetJob;
use App\Models\SyncTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class SyncDispatchService
{
    public function __construct(private readonly GitHubClient $github) {}

    public function startOrResume(SyncTarget $target): bool
    {
        // Both writes must use the same connection; afterCommit dispatch alone has a gap.
        $queueConnection = config('queue.connections.database.connection') ?? config('database.default');
        if ($queueConnection !== ($target->getConnectionName() ?? config('database.default'))) {
            throw new LogicException('Synchronization requires the database queue and targets to share a connection.');
        }

        return DB::connection($queueConnection)->transaction(function () use ($target) {
            if (! $target->markQueued($this->github->querySignature())) {
                return false;
            }

            Queue::connection('database')->push((new SyncTargetJob($target))->beforeCommit());

            return true;
        });
    }
}
