<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRepositoriesRequest extends FormRequest
{
    /** Sort keys accepted from the query string, mapped to real columns (never trust a raw column name). */
    public const SORTS = [
        'name' => 'name',
        'stars' => 'stargazers_count',
        'issues' => 'open_issues_count',
        'updated' => 'external_updated_at',
    ];

    /**
     * Validate repository search, filter and allowed sorting parameters.
     *
     * @return array<string, array<int, mixed>> Validation rules keyed by query parameter.
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'target' => ['nullable', 'integer'],
            'language' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'missing' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Resolve the validated sorting key or use the default update-time order.
     *
     * @return string Allowed sorting key used by the UI and column mapping.
     */
    public function sortKey(): string
    {
        return $this->validated('sort') ?? 'updated';
    }

    /**
     * Map the sorting key to a whitelisted database column.
     *
     * @return string Repository column used for ordering.
     */
    public function sortColumn(): string
    {
        return self::SORTS[$this->sortKey()];
    }

    /**
     * Resolve the requested direction or the default for the selected sort key.
     *
     * @return 'asc'|'desc' Validated or default sorting direction.
     */
    public function sortDirection(): string
    {
        $direction = $this->validated('direction') ?? ($this->sortKey() === 'name' ? 'asc' : 'desc');

        return $direction === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Check whether the request asks to include repositories marked as missing.
     *
     * @return bool True if the missing-repository filter is enabled.
     */
    public function showMissing(): bool
    {
        return $this->boolean('missing');
    }
}
