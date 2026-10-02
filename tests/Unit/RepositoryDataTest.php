<?php

namespace Tests\Unit;

use App\Enums\TargetType;
use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\RepositoryData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\FakesGitHub;

class RepositoryDataTest extends TestCase
{
    use FakesGitHub;

    public function test_it_maps_consumed_fields_to_database_attributes(): void
    {
        $data = RepositoryData::fromApi($this->githubRepository(42, 'framework', ['archived' => true]));
        $attributes = $data->toAttributes();

        $this->assertSame([
            'external_id' => 42,
            'name' => 'framework',
            'full_name' => 'laravel/framework',
            'description' => 'The framework repository.',
            'html_url' => 'https://github.com/laravel/framework',
            'language' => 'PHP',
            'stargazers_count' => 100,
            'open_issues_count' => 3,
            'is_archived' => true,
            'external_updated_at' => $data->externalUpdatedAt,
        ], $attributes);
        $this->assertSame('2026-09-30T10:00:00+00:00', $data->externalUpdatedAt->toIso8601String());
        $this->assertSame(TargetType::Organization, $data->ownerType);
    }

    public function test_missing_optional_fields_keep_their_defaults(): void
    {
        $data = RepositoryData::fromApi(['id' => 1, 'name' => 'x', 'full_name' => 'a/x', 'html_url' => 'https://github.com/a/x']);
        $this->assertNull($data->description);
        $this->assertNull($data->language);
        $this->assertNull($data->externalUpdatedAt);
        $this->assertNull($data->ownerType);
        $this->assertSame(0, $data->stargazersCount);
        $this->assertSame(0, $data->openIssuesCount);
        $this->assertFalse($data->isArchived);
    }

    public function test_nullable_fields_and_unknown_owner_type_are_supported(): void
    {
        $data = RepositoryData::fromApi($this->githubRepository(overrides: [
            'description' => null, 'language' => null, 'updated_at' => null, 'owner' => ['type' => 'Bot'],
        ]));
        $this->assertNull($data->description);
        $this->assertNull($data->language);
        $this->assertNull($data->externalUpdatedAt);
        $this->assertNull($data->ownerType);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'zero id' => ['id', 0],
            'negative id' => ['id', -1],
            'string id' => ['id', '1'],
            'null id' => ['id', null],
            'float id' => ['id', 1.5],
            'empty name' => ['name', ' '],
            'array name' => ['name', []],
            'null full name' => ['full_name', null],
            'invalid url' => ['html_url', 'not a URL'],
            'unsupported url scheme' => ['html_url', 'ftp://github.com/a/x'],
            'description object' => ['description', new \stdClass],
            'numeric language' => ['language', 42],
            'negative stars' => ['stargazers_count', -1],
            'string issues' => ['open_issues_count', '3'],
            'null stars' => ['stargazers_count', null],
            'string archived' => ['archived', 'false'],
            'null archived' => ['archived', null],
            'relative date' => ['updated_at', 'tomorrow'],
            'impossible date' => ['updated_at', '2026-02-30T10:00:00Z'],
            'invalid timezone offset' => ['updated_at', '2026-09-30T10:00:00+99:99'],
            'wrong date type' => ['updated_at', 42],
            'scalar owner' => ['owner', 'User'],
            'empty list owner' => ['owner', []],
            'list owner' => ['owner', ['User']],
            'invalid owner type' => ['owner', ['type' => 1]],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_consumed_fields_raise_a_safe_integration_exception(string $field, mixed $value): void
    {
        $this->expectException(GitHubException::class);
        RepositoryData::fromApi($this->githubRepository(overrides: [$field => $value]));
    }

    /** @return array<string, array{string}> */
    public static function requiredFields(): array
    {
        return [
            'id' => ['id'],
            'name' => ['name'],
            'full_name' => ['full_name'],
            'html_url' => ['html_url'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_required_fields_cannot_be_omitted(string $field): void
    {
        $payload = $this->githubRepository();
        unset($payload[$field]);
        $this->expectException(GitHubException::class);
        RepositoryData::fromApi($payload);
    }
}
