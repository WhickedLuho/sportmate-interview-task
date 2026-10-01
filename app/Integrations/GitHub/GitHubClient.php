<?php

namespace App\Integrations\GitHub;

use App\Integrations\GitHub\Exceptions\GitHubException;
use App\Integrations\GitHub\Exceptions\GitHubNotFoundException;
use App\Integrations\GitHub\Exceptions\GitHubRateLimitedException;
use App\Integrations\GitHub\Exceptions\GitHubUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class that talks HTTP to GitHub.
 *
 * It returns plain DTOs and translates every failure into a {@see GitHubException}
 * subclass, so callers never deal with HTTP status codes or headers.
 *
 * It deliberately does not retry: the queued job owns retry and back-off policy.
 */
class GitHubClient
{
    private const PER_PAGE = 100;

    /** Safety valve (10,000 repositories); better to fail than to store a partial list as complete. */
    private const MAX_PAGES = 100;

    private const DEFAULT_RETRY_SECONDS = 60;

    public function __construct(
        #[Config('services.github.base_url')] private readonly string $baseUrl,
        #[Config('services.github.token')] private readonly ?string $token,
        #[Config('services.github.timeout')] private readonly int $timeout,
        #[Config('services.github.connect_timeout')] private readonly int $connectTimeout,
    ) {}

    /**
     * Fetch every public repository owned by a user or organization, across all pages.
     *
     * `/users/{login}/repos` works for organizations too, so one endpoint covers both.
     *
     * @return list<RepositoryData>
     *
     * @throws GitHubException
     */
    public function repositories(string $login): array
    {
        $repositories = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->get('/users/'.rawurlencode($login).'/repos', [
                'type' => 'owner',
                // A stable order keeps pages consistent even if a repository is
                // pushed to while we are paginating (the default is "created").
                'sort' => 'full_name',
                'direction' => 'asc',
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            $items = $response->json();

            if (! is_array($items)) {
                throw new GitHubException('GitHub returned a non-list repositories payload.');
            }

            foreach ($items as $item) {
                $repositories[] = RepositoryData::fromApi($item);
            }

            if (! $this->hasNextPage($response)) {
                return $repositories;
            }
        }

        throw new GitHubException('Aborted: more than '.self::MAX_PAGES." pages of repositories for [{$login}].");
    }

    /**
     * @param  array<string, scalar>  $query
     *
     * @throws GitHubException
     */
    private function get(string $path, array $query): Response
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            // Covers DNS failures, refused connections and timeouts.
            throw new GitHubUnavailableException('Could not connect to GitHub: '.$e->getMessage(), previous: $e);
        }

        return $this->ensureSuccessful($response, $path);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent(config('app.name').' repository sync')
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->when($this->token, fn (PendingRequest $request) => $request->withToken($this->token));
    }

    /**
     * @throws GitHubException
     */
    private function ensureSuccessful(Response $response, string $path): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status === 404) {
            throw new GitHubNotFoundException("GitHub returned 404 for [{$path}].");
        }

        if ($this->isRateLimited($response)) {
            throw new GitHubRateLimitedException($this->retryAt($response));
        }

        if ($status >= 500) {
            throw new GitHubUnavailableException("GitHub returned {$status} for [{$path}].");
        }

        // e.g. 401 (invalid token) or 403 for a reason other than rate limiting.
        throw new GitHubException("GitHub returned {$status} for [{$path}]: ".$response->json('message', 'no message'));
    }

    /**
     * GitHub signals both the primary limit (403 with remaining = 0) and the
     * secondary/abuse limit (403 or 429 with Retry-After) differently.
     */
    private function isRateLimited(Response $response): bool
    {
        return $response->status() === 429
            || ($response->status() === 403
                && ($response->header('X-RateLimit-Remaining') === '0' || $response->hasHeader('Retry-After')));
    }

    private function retryAt(Response $response): CarbonImmutable
    {
        if ($response->hasHeader('Retry-After')) {
            return CarbonImmutable::now()->addSeconds((int) $response->header('Retry-After'));
        }

        if ($response->hasHeader('X-RateLimit-Reset')) {
            return CarbonImmutable::createFromTimestamp((int) $response->header('X-RateLimit-Reset'));
        }

        return CarbonImmutable::now()->addSeconds(self::DEFAULT_RETRY_SECONDS);
    }

    private function hasNextPage(Response $response): bool
    {
        return str_contains($response->header('Link'), 'rel="next"');
    }
}
