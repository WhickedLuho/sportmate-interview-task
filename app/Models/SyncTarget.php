<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\TargetType;
use Carbon\CarbonInterface;
use Database\Factories\SyncTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A GitHub user or organization whose public repositories are synchronized.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property TargetType|null $type
 * @property SyncStatus $status
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $retry_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'status', 'last_attempted_at', 'last_synced_at', 'retry_at', 'last_error'])]
class SyncTarget extends Model
{
    /** @use HasFactory<SyncTargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TargetType::class,
            'status' => SyncStatus::class,
            'last_attempted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'retry_at' => 'datetime',
        ];
    }

    /**
     * GitHub logins are case-insensitive, so they are stored lowercased to keep
     * the (user_id, name) unique constraint meaningful.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value) => strtolower(trim($value)));
    }

    /**
     * Targets that are due for a scheduled synchronization: nothing is pending or running
     * for them and the last attempt (successful or not) is older than the given moment.
     * A target that was never attempted is always due.
     *
     * @param  Builder<SyncTarget>  $query
     */
    #[Scope]
    protected function due(Builder $query, CarbonInterface $attemptedBefore): void
    {
        $query
            ->whereNotIn('status', array_map(fn (SyncStatus $status) => $status->value, SyncStatus::inProgress()))
            ->where(fn (Builder $query) => $query
                ->whereNull('last_attempted_at')
                ->orWhere('last_attempted_at', '<=', $attemptedBefore));
    }

    /**
     * Atomically move the target to "queued", unless a sync is already pending
     * or running. A single conditional UPDATE means two simultaneous requests
     * cannot both win, without needing a lock.
     *
     * @return bool whether this call claimed the target (and a job should be dispatched)
     */
    public function markQueued(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereNotIn('status', array_map(fn (SyncStatus $status) => $status->value, SyncStatus::inProgress()))
            ->update(['status' => SyncStatus::Queued, 'updated_at' => now()]) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * Stop a synchronization that is still waiting (queued, or paused by a rate limit).
     *
     * This does not remove the job from the queue (with the database queue that would mean
     * searching serialized payloads). The job notices on wake-up that the target is no longer
     * pending and exits without calling GitHub. If the user starts a new sync before then,
     * the new dispatch is dropped by the unique lock and the old job simply does the work.
     *
     * @return bool whether something was cancelled
     */
    public function markCancelled(): bool
    {
        $cancelled = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (SyncStatus $status) => $status->value, [SyncStatus::Queued, SyncStatus::RateLimited]))
            // Not an error, so there is nothing to show as one.
            ->update(['status' => SyncStatus::Idle, 'retry_at' => null, 'last_error' => null, 'updated_at' => now()]) === 1;

        $this->refresh();

        return $cancelled;
    }

    public function markSyncing(): void
    {
        $this->update(['status' => SyncStatus::Syncing, 'last_attempted_at' => now(), 'retry_at' => null]);
    }

    public function markSynced(?TargetType $type): void
    {
        $this->update([
            'status' => SyncStatus::Synced,
            'type' => $type ?? $this->type,
            'last_synced_at' => now(),
            'retry_at' => null,
            'last_error' => null,
        ]);
    }

    /** A transient failure: the job will run again, so the target goes back to "queued". */
    public function markRetrying(string $message): void
    {
        $this->update(['status' => SyncStatus::Queued, 'retry_at' => null, 'last_error' => $message]);
    }

    public function markRateLimited(string $message, CarbonInterface $retryAt): void
    {
        $this->update(['status' => SyncStatus::RateLimited, 'retry_at' => $retryAt, 'last_error' => $message]);
    }

    public function markFailed(string $message): void
    {
        $this->update(['status' => SyncStatus::Failed, 'retry_at' => null, 'last_error' => $message]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
