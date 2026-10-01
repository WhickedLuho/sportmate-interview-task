<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A GitHub repository as last seen during a synchronization.
 *
 * @property int $id
 * @property int $sync_target_id
 * @property int $external_id
 * @property string $name
 * @property string $full_name
 * @property string|null $description
 * @property string $html_url
 * @property string|null $language
 * @property int $stargazers_count
 * @property int $open_issues_count
 * @property bool $is_archived
 * @property Carbon|null $external_updated_at
 * @property Carbon|null $missing_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'external_id', 'name', 'full_name', 'description', 'html_url', 'language',
    'stargazers_count', 'open_issues_count', 'is_archived', 'external_updated_at', 'missing_at',
])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'external_updated_at' => 'datetime',
            'missing_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncTarget, $this>
     */
    public function syncTarget(): BelongsTo
    {
        return $this->belongsTo(SyncTarget::class);
    }
}
