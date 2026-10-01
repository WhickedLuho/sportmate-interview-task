<?php

namespace App\Http\Resources;

use App\Models\SyncTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncTarget
 */
class SyncTargetResource extends JsonResource
{
    /**
     * Expects the `active_repositories_count` and `missing_repositories_count` aggregates to be loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'status' => $this->status->value,
            'can_sync' => $this->status->canStartSync(),
            'last_attempted_at' => $this->last_attempted_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'repositories_count' => (int) ($this->active_repositories_count ?? 0),
            'missing_repositories_count' => (int) ($this->missing_repositories_count ?? 0),
        ];
    }
}
