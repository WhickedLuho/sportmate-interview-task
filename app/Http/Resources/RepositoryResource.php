<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
{
    /**
     * Expose stored repository fields and its loaded target to the UI.
     *
     * @param  Request  $request  Request for which the resource is serialized.
     *
     * @return array<string, mixed> Repository display fields and optional target data.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'description' => $this->description,
            'html_url' => $this->html_url,
            'language' => $this->language,
            'stargazers_count' => $this->stargazers_count,
            'open_issues_count' => $this->open_issues_count,
            'is_archived' => $this->is_archived,
            'is_missing' => $this->missing_at !== null,
            'external_updated_at' => $this->external_updated_at?->toIso8601String(),
            'target' => $this->whenLoaded('syncTarget', fn () => [
                'id' => $this->syncTarget->id,
                'name' => $this->syncTarget->name,
            ]),
        ];
    }
}
