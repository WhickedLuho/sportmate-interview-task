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
     * @return array<string, array<int, mixed>>
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

    public function sortKey(): string
    {
        return $this->validated('sort') ?? 'updated';
    }

    public function sortColumn(): string
    {
        return self::SORTS[$this->sortKey()];
    }

    /**
     * @return 'asc'|'desc'
     */
    public function sortDirection(): string
    {
        $direction = $this->validated('direction') ?? ($this->sortKey() === 'name' ? 'asc' : 'desc');

        return $direction === 'asc' ? 'asc' : 'desc';
    }

    public function showMissing(): bool
    {
        return $this->boolean('missing');
    }
}
