<?php

namespace App\Integrations\GitHub;

use App\Enums\TargetType;
use Carbon\CarbonImmutable;

/**
 * A repository as described by the GitHub API, mapped to the shape we store locally.
 *
 * Keeping the mapping here means the rest of the application never sees raw
 * GitHub payloads, and a change in the API only touches this class.
 */
final readonly class RepositoryData
{
    public function __construct(
        public int $externalId,
        public string $name,
        public string $fullName,
        public ?string $description,
        public string $htmlUrl,
        public ?string $language,
        public int $stargazersCount,
        public int $openIssuesCount,
        public bool $isArchived,
        public ?CarbonImmutable $externalUpdatedAt,
        public ?TargetType $ownerType,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  one element of GitHub's "list repositories" response
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            externalId: (int) $payload['id'],
            name: (string) $payload['name'],
            fullName: (string) $payload['full_name'],
            description: $payload['description'] ?? null,
            htmlUrl: (string) $payload['html_url'],
            language: $payload['language'] ?? null,
            stargazersCount: (int) ($payload['stargazers_count'] ?? 0),
            // GitHub counts open pull requests as issues in this field.
            openIssuesCount: (int) ($payload['open_issues_count'] ?? 0),
            isArchived: (bool) ($payload['archived'] ?? false),
            externalUpdatedAt: isset($payload['updated_at']) ? CarbonImmutable::parse($payload['updated_at']) : null,
            ownerType: isset($payload['owner']['type']) ? TargetType::fromGitHub($payload['owner']['type']) : null,
        );
    }

    /**
     * Columns of the local `repositories` table (without the target foreign key).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
            'full_name' => $this->fullName,
            'description' => $this->description,
            'html_url' => $this->htmlUrl,
            'language' => $this->language,
            'stargazers_count' => $this->stargazersCount,
            'open_issues_count' => $this->openIssuesCount,
            'is_archived' => $this->isArchived,
            'external_updated_at' => $this->externalUpdatedAt,
        ];
    }
}
