<?php

namespace App\Integrations\GitHub\Exceptions;

use Carbon\CarbonImmutable;

/** GitHub refused the request because a rate limit was exceeded. Retry after {@see $retryAt}. */
class GitHubRateLimitedException extends GitHubException
{
    public function __construct(public readonly CarbonImmutable $retryAt, string $message = 'GitHub rate limit exceeded.')
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return 'GitHub rate limit reached. The synchronization will be retried automatically.';
    }
}
