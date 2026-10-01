<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\TargetType;
use Database\Factories\SyncTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'status', 'last_attempted_at', 'last_synced_at', 'last_error'])]
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
