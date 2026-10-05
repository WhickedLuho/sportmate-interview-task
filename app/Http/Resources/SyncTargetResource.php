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
     * Expose target status, progress and preloaded repository counts to the UI.
     *
     * @param  Request  $request  Request for which the resource is serialized.
     *
     * @return array<string, mixed> Target fields and available UI actions.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'status' => $this->status->value,
            'can_sync' => $this->status->canStartSync(),
            'can_cancel' => $this->status->canCancel(),
            'can_resume' => $this->sync_run_id !== null && $this->status->canStartSync(),
            'pages_saved' => $this->sync_run_id !== null ? $this->next_page - 1 : null,
            'retry_at' => $this->retry_at?->toIso8601String(),
            'last_attempted_at' => $this->last_attempted_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'repositories_count' => (int) ($this->active_repositories_count ?? 0),
            'missing_repositories_count' => (int) ($this->missing_repositories_count ?? 0),
        ];
    }
}
