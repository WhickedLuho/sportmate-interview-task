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

    /**
     * List statuses with a queued, running or rate-limited synchronization.
     *
     * @return list<self> Statuses that represent active work.
     */
    public static function inProgress(): array
    {
        return [self::Queued, self::Syncing, self::RateLimited];
    }

    /**
     * Check whether this status represents active synchronization work.
     *
     * @return bool True for queued, syncing or rate-limited status.
     */
    public function isInProgress(): bool
    {
        return in_array($this, self::inProgress(), true);
    }

    /**
     * Check whether this status permits a new or resumed dispatch.
     *
     * @return bool True when no synchronization is currently active.
     */
    public function canStartSync(): bool
    {
        return ! $this->isInProgress();
    }

    /**
     * Check whether this status permits stopping waiting work.
     *
     * @return bool True for queued or rate-limited status; false while processing.
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::Queued, self::RateLimited], true);
    }
}
