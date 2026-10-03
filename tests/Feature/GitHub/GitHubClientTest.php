<?php

namespace Tests\Feature\GitHub;

use App\Enums\TargetType;
use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\Exceptions\GitHubNotFoundException;
use App\Integrations\GitHub\Exceptions\GitHubRateLimitedException;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use App\Integrations\GitHub\GitHubClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    private const URL = 'https://api.github.com/users/laravel/repos*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.token' => null]);
        Http::preventStrayRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $id = 1, string $name = 'framework'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'full_name' => "laravel/{$name}",
            'description' => 'The Laravel Framework.',
            'html_url' => "https://github.com/laravel/{$name}",
            'language' => 'PHP',
            'stargazers_count' => 30000,
            'open_issues_count' => 12,
            'archived' => false,
            'updated_at' => '2026-09-30T10:00:00Z',
            'owner' => ['login' => 'laravel', 'type' => 'Organization'],
        ];
    }

    private function client(): GitHubClient
    {
        return $this->app->make(GitHubClient::class);
    }

    public function test_it_maps_the_github_payload_to_repository_data(): void
    {
        Http::fake([self::URL => Http::response([$this->payload()])]);

        $repositories = $this->client()->repositories('laravel');

        $this->assertCount(1, $repositories);
        $repository = $repositories[0];
        $this->assertSame(1, $repository->externalId);
        $this->assertSame('laravel/framework', $repository->fullName);
        $this->assertSame('PHP', $repository->language);
        $this->assertSame(30000, $repository->stargazersCount);
        $this->assertSame(12, $repository->openIssuesCount);
        $this->assertFalse($repository->isArchived);
        $this->assertSame('2026-09-30T10:00:00+00:00', $repository->externalUpdatedAt->toIso8601String());
        $this->assertSame(TargetType::Organization, $repository->ownerType);
    }

    public function test_it_tolerates_missing_optional_fields(): void
    {
        Http::fake([self::URL => Http::response([
            ['id' => 5, 'name' => 'x', 'full_name' => 'laravel/x', 'description' => null, 'html_url' => 'https://github.com/laravel/x', 'language' => null],
        ])]);

        $repository = $this->client()->repositories('laravel')[0];

        $this->assertNull($repository->description);
        $this->assertNull($repository->language);
        $this->assertNull($repository->externalUpdatedAt);
        $this->assertNull($repository->ownerType);
        $this->assertSame(0, $repository->stargazersCount);
    }

    public function test_it_follows_pagination_until_there_is_no_next_link(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push([$this->payload(1, 'a')], 200, ['Link' => '<https://api.github.com/x?page=2>; rel="next", <https://api.github.com/x?page=2>; rel="last"'])
            ->push([$this->payload(2, 'b')], 200, ['Link' => '<https://api.github.com/x?page=1>; rel="prev", <https://api.github.com/x?page=1>; rel="first"']),
        ]);

        $repositories = $this->client()->repositories('laravel');

        $this->assertSame([1, 2], array_map(fn ($r) => $r->externalId, $repositories));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['page'] == 1 && $request['per_page'] == 100);
        Http::assertSent(fn (Request $request) => $request['page'] == 2);
    }

    public function test_it_returns_an_empty_list_for_an_account_without_repositories(): void
    {
        Http::fake([self::URL => Http::response([])]);

        $this->assertSame([], $this->client()->repositories('laravel'));
    }

    public function test_one_page_returns_the_next_flag_without_fetching_another_page(): void
    {
        Http::fake([self::URL => Http::response([$this->payload()], 200, ['Link' => '<https://api.github.com/x?page=2>; rel="next"'])]);
        $page = $this->client()->repositoriesPage('laravel', 1);
        $this->assertTrue($page->hasNextPage);
        $this->assertCount(1, $page->repositories);
        Http::assertSentCount(1);
    }

    public function test_it_sends_the_token_only_when_configured(): void
    {
        Http::fake([self::URL => Http::response([])]);

        $this->client()->repositories('laravel');
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));

        config(['services.github.token' => 'test-token']);
        $this->client()->repositories('laravel');
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_a_missing_account_raises_not_found(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'Not Found'], 404)]);

        $this->expectException(GitHubNotFoundException::class);

        $this->client()->repositories('laravel');
    }

    public function test_the_primary_rate_limit_reports_when_to_retry(): void
    {
        $reset = now()->addMinutes(20)->timestamp;
        Http::fake([self::URL => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $reset,
        ])]);

        try {
            $this->client()->repositories('laravel');
            $this->fail('Expected a rate limit exception.');
        } catch (GitHubRateLimitedException $e) {
            $this->assertSame($reset, $e->retryAt->timestamp);
        }
    }

    public function test_the_secondary_rate_limit_uses_retry_after(): void
    {
        Http::fake([self::URL => Http::response([], 429, ['Retry-After' => '90'])]);

        try {
            $this->client()->repositories('laravel');
            $this->fail('Expected a rate limit exception.');
        } catch (GitHubRateLimitedException $e) {
            $this->assertEqualsWithDelta(now()->addSeconds(90)->timestamp, $e->retryAt->timestamp, 2);
        }
    }

    public function test_a_forbidden_response_without_rate_limit_headers_is_not_a_rate_limit(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'Bad credentials'], 403, ['X-RateLimit-Remaining' => '55'])]);

        try {
            $this->client()->repositories('laravel');
            $this->fail('Expected an exception.');
        } catch (GitHubException $e) {
            $this->assertNotInstanceOf(GitHubRateLimitedException::class, $e);
            $this->assertStringContainsString('Bad credentials', $e->getMessage());
        }
    }

    public function test_server_errors_are_reported_as_unavailable(): void
    {
        Http::fake([self::URL => Http::response('Bad gateway', 502)]);

        $this->expectException(GitHubUnavailableException::class);

        $this->client()->repositories('laravel');
    }

    public function test_connection_failures_are_reported_as_unavailable(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->expectException(GitHubUnavailableException::class);

        $this->client()->repositories('laravel');
    }

    public function test_an_unexpected_payload_shape_is_rejected(): void
    {
        Http::fake([self::URL => Http::response('"not a list"', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(GitHubException::class);

        $this->client()->repositories('laravel');
    }

    public function test_user_messages_do_not_leak_technical_details(): void
    {
        $this->assertStringNotContainsString('404', (new GitHubNotFoundException('GitHub returned 404 for [/users/x/repos].'))->userMessage());
    }

    /** @return array<string, array{string}> */
    public static function invalidPayloads(): array
    {
        return [
            'empty object' => ['{}'],
            'numeric object keys' => ['{"0":{"id":1}}'],
            'malformed JSON' => ['[{'],
            'null' => ['null'],
            'scalar item' => ['[42]'],
            'list item' => ['[[]]'],
            'missing required fields' => ['[{}]'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_json_lists_and_items_are_rejected(string $body): void
    {
        Http::fake([self::URL => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $this->expectException(GitHubException::class);

        $this->client()->repositories('laravel');
    }
}
