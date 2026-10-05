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
use Illuminate\Support\Str;

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
 * @property string|null $sync_run_id
 * @property string|null $dispatch_id
 * @property int $next_page
 * @property string|null $sync_query_signature
 * @property Carbon|null $last_page_saved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'status', 'last_attempted_at', 'last_synced_at', 'retry_at', 'last_error', 'sync_run_id', 'dispatch_id', 'next_page', 'sync_query_signature', 'last_page_saved_at'])]
class SyncTarget extends Model
{
    /** @use HasFactory<SyncTargetFactory> */
    use HasFactory;

    /**
     * Define casts for target enums, timestamps and the saved page cursor.
     *
     * @return array<string, string> Attribute names mapped to their cast definitions.
     */
    protected function casts(): array
    {
        return [
            'type' => TargetType::class,
            'status' => SyncStatus::class,
            'last_attempted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'retry_at' => 'datetime',
            'next_page' => 'integer',
            'last_page_saved_at' => 'datetime',
        ];
    }

    /**
     * Normalize GitHub logins so case-insensitive names remain unique.
     *
     * @return Attribute<string, string> Setter that trims and lowercases the target name.
     */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value) => strtolower(trim($value)));
    }

    /**
     * Restrict the query to inactive targets whose last attempt is old or absent.
     *
     * @param  Builder<SyncTarget>  $query  Target query to constrain.
     * @param  CarbonInterface  $attemptedBefore  Inclusive cutoff for the last attempt time.
     *
     * @return void
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
     * Atomically claim the target, preserving compatible progress when resuming.
     *
     * @param  string|null  $querySignature  Query signature to match; null skips signature comparison.
     *
     * @return bool True if claimed; false if already active or changed during the claim.
     */
    public function markQueued(?string $querySignature = null): bool
    {
        $this->refresh();
        $resume = $this->sync_run_id !== null
            && ($querySignature === null || $this->sync_query_signature === $querySignature);

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('sync_run_id', $this->sync_run_id)
            ->where('dispatch_id', $this->dispatch_id)
            ->whereNotIn('status', array_map(fn (SyncStatus $status) => $status->value, SyncStatus::inProgress()))
            ->update([
                'status' => SyncStatus::Queued,
                'sync_run_id' => $resume ? $this->sync_run_id : (string) Str::uuid(),
                'dispatch_id' => (string) Str::uuid(),
                'next_page' => $resume ? $this->next_page : 1,
                'sync_query_signature' => $querySignature ?? $this->sync_query_signature,
                'last_page_saved_at' => $resume ? $this->last_page_saved_at : null,
                'retry_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * Stop waiting work while preserving the run and its committed page cursor.
     *
     * @return bool True if a queued or rate-limited target was stopped.
     */
    public function markCancelled(): bool
    {
        $cancelled = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (SyncStatus $status) => $status->value, [SyncStatus::Queued, SyncStatus::RateLimited]))
            // Not an error, so there is nothing to show as one.
            ->update(['status' => SyncStatus::Idle, 'dispatch_id' => null, 'retry_at' => null, 'last_error' => null, 'updated_at' => now()]) === 1;

        $this->refresh();

        return $cancelled;
    }

    /**
     * Mark the target as processing and record the attempt time.
     *
     * @return void
     */
    public function markSyncing(): void
    {
        $this->update(['status' => SyncStatus::Syncing, 'last_attempted_at' => now(), 'retry_at' => null]);
    }

    /**
     * Record full success and clear progress so the next sync starts at page one.
     *
     * @param  TargetType|null  $type  Discovered account type; null preserves the current type.
     *
     * @return void
     */
    public function markSynced(?TargetType $type): void
    {
        $this->update([
            'status' => SyncStatus::Synced,
            'type' => $type ?? $this->type,
            'last_synced_at' => now(),
            'sync_run_id' => null,
            'dispatch_id' => null,
            'next_page' => 1,
            'sync_query_signature' => null,
            'retry_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Return the target to queued status after a transient failure.
     *
     * @param  string  $message  User-safe failure message stored on the target.
     *
     * @return void
     */
    public function markRetrying(string $message): void
    {
        $this->update(['status' => SyncStatus::Queued, 'retry_at' => null, 'last_error' => $message]);
    }

    /**
     * Record a rate-limit pause and its scheduled retry time.
     *
     * @param  string  $message  User-safe rate-limit message.
     * @param  CarbonInterface  $retryAt  Time when the job is scheduled to retry.
     *
     * @return void
     */
    public function markRateLimited(string $message, CarbonInterface $retryAt): void
    {
        $this->update(['status' => SyncStatus::RateLimited, 'retry_at' => $retryAt, 'last_error' => $message]);
    }

    /**
     * Record a failed synchronization and clear any scheduled retry time.
     *
     * @param  string  $message  User-safe failure message stored on the target.
     *
     * @return void
     */
    public function markFailed(string $message): void
    {
        $this->update(['status' => SyncStatus::Failed, 'retry_at' => null, 'last_error' => $message]);
    }

    /**
     * Define the target's owning user relationship.
     *
     * @return BelongsTo<User, $this> Relationship to the user who added the target.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Define the repositories stored under this target.
     *
     * @return HasMany<Repository, $this> Relationship to the target's local repositories.
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
