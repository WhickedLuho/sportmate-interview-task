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
    /**
     * Represent the repository fields used by the local application.
     *
     * @param  int  $externalId  Stable numeric repository identifier from GitHub.
     * @param  string  $name  Repository name without its owner.
     * @param  string  $fullName  Repository name prefixed by its owner.
     * @param  string|null  $description  Repository description, when available.
     * @param  string  $htmlUrl  Web URL of the repository.
     * @param  string|null  $language  Primary programming language, when available.
     * @param  int  $stargazersCount  Number of GitHub stars.
     * @param  int  $openIssuesCount  Number of open issues, including pull requests.
     * @param  bool  $isArchived  Whether the repository is archived.
     * @param  CarbonImmutable|null  $externalUpdatedAt  Last update time supplied by GitHub.
     * @param  TargetType|null  $ownerType  Recognized owner type, or null if unknown.
     */
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
     * Validate a GitHub repository payload and map it to local fields.
     *
     * @param  array<string, mixed>  $payload  One repository object from the API response.
     *
     * @return self Validated repository data ready for persistence.
     *
     * @throws GitHubException
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

    /**
     * Read a required string field and reject missing or blank values.
     *
     * @param  array<string, mixed>  $payload  API fields to inspect.
     * @param  string  $field  Name of the required field.
     *
     * @return string Nonempty field value.
     *
     * @throws GitHubException
     */
    private static function requiredString(array $payload, string $field): string
    {
        $value = self::nullableString($payload, $field);
        if ($value === null || trim($value) === '') {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
    }

    /**
     * Read an optional string field while rejecting other value types.
     *
     * @param  array<string, mixed>  $payload  API fields to inspect.
     * @param  string  $field  Name of the optional field.
     *
     * @return string|null Field value, or null when missing or explicitly null.
     *
     * @throws GitHubException
     */
    private static function nullableString(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
    }

    /**
     * Read a nonnegative integer, using the default only when the field is absent.
     *
     * @param  array<string, mixed>  $payload  API fields to inspect.
     * @param  string  $field  Name of the integer field.
     * @param  int|null  $default  Fallback for an absent field; null makes absence invalid.
     *
     * @return int Validated nonnegative field value.
     *
     * @throws GitHubException
     */
    private static function nonNegativeInteger(array $payload, string $field, ?int $default = null): int
    {
        $value = array_key_exists($field, $payload) ? $payload[$field] : $default;
        if (! is_int($value) || $value < 0) {
            throw new GitHubException("Invalid repository field: {$field}.");
        }

        return $value;
    }

    /**
     * Map the DTO to local repository columns without target or run metadata.
     *
     * @return array<string, mixed> Column values used by the repository upsert.
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
