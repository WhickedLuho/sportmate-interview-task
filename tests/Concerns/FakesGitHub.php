<?php

namespace Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for tests that need GitHub's repository list without touching the network.
 */
trait FakesGitHub
{
    /**
     * One repository as GitHub's API describes it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function githubRepository(int $id = 1, string $name = 'framework', array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'name' => $name,
            'full_name' => "laravel/{$name}",
            'description' => "The {$name} repository.",
            'html_url' => "https://github.com/laravel/{$name}",
            'language' => 'PHP',
            'stargazers_count' => 100,
            'open_issues_count' => 3,
            'archived' => false,
            'updated_at' => '2026-09-30T10:00:00Z',
            'owner' => ['login' => 'laravel', 'type' => 'Organization'],
        ], $overrides);
    }

    /**
     * Replace any earlier fake with a single response for every GitHub request.
     *
     * Http::fake() appends stubs and the first match wins, so a second call within
     * one test would silently be ignored without the reset.
     */
    protected function fakeGitHub(PromiseInterface|callable $response): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => $response]);
    }

    /**
     * Fake GitHub's repository list with a single page of results.
     *
     * @param  list<array<string, mixed>>  $repositories
     */
    protected function fakeGitHubRepositories(array $repositories): void
    {
        $this->fakeGitHub(Http::response($repositories));
    }
}
