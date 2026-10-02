<?php

namespace App\Integrations\GitHub;

use App\Enums\TargetType;
use App\Integrations\GitHub\Exceptions\GitHubException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use stdClass;

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
        $id = self::nonNegativeInteger($payload, 'id');
        if ($id === 0) {
            throw new GitHubException('Invalid repository field: id.');
        }

        $url = self::requiredString($payload, 'html_url');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new GitHubException('Invalid repository field: html_url.');
        }

        $archived = $payload['archived'] ?? false;
        if (array_key_exists('archived', $payload) && ! is_bool($payload['archived'])) {
            throw new GitHubException('Invalid repository field: archived.');
        }

        $updatedAt = self::nullableString($payload, 'updated_at');
        $date = null;
        if ($updatedAt !== null) {
            // Reject relative dates and normalized impossible dates, not just parse errors.
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $updatedAt)) {
                throw new GitHubException('Invalid repository field: updated_at.');
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $updatedAt);
            if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
                throw new GitHubException('Invalid repository field: updated_at.');
            }
            $date = CarbonImmutable::instance($parsed);
        }

        $ownerType = null;
        if (array_key_exists('owner', $payload)) {
            $owner = $payload['owner'];
            if ($owner instanceof stdClass) {
                $owner = get_object_vars($owner);
            } elseif (! is_array($owner) || array_is_list($owner)) {
                throw new GitHubException('Invalid repository field: owner.');
            }
            $type = self::nullableString($owner, 'type');
            $ownerType = $type === null ? null : TargetType::fromGitHub($type);
        }

        return new self(
            externalId: $id,
            name: self::requiredString($payload, 'name'),
            fullName: self::requiredString($payload, 'full_name'),
            description: self::nullableString($payload, 'description'),
            htmlUrl: $url,
            language: self::nullableString($payload, 'language'),
            stargazersCount: self::nonNegativeInteger($payload, 'stargazers_count', 0),
            // GitHub counts open pull requests as issues in this field.
            openIssuesCount: self::nonNegativeInteger($payload, 'open_issues_count', 0),
            isArchived: $archived,
            externalUpdatedAt: $date,
            ownerType: $ownerType,
        );
    }

    /** @param array<string, mixed> $payload */
    private static function requiredString(array $payload, string $field): string
    {
        $value = self::nullableString($payload, $field);
        if ($value === null || trim($value) === '') {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function nullableString(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function nonNegativeInteger(array $payload, string $field, ?int $default = null): int
    {
        $value = array_key_exists($field, $payload) ? $payload[$field] : $default;
        if (! is_int($value) || $value < 0) {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
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
