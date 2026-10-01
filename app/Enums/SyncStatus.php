<?php

namespace App\Enums;

enum SyncStatus: string
{
    /** Never synchronized yet. */
    case Idle = 'idle';
    /** A job has been dispatched and is waiting for a worker. */
    case Queued = 'queued';
    /** A worker is talking to GitHub right now. */
    case Syncing = 'syncing';
    case Synced = 'synced';
    case Failed = 'failed';
    /** GitHub rate limit hit; the job was released and will be retried. */
    case RateLimited = 'rate_limited';

    /** Whether a new synchronization may be requested from this status. */
    public function canStartSync(): bool
    {
        return ! in_array($this, [self::Queued, self::Syncing], true);
    }
}
