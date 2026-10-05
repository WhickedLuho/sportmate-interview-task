<?php

namespace App\Integrations\GitHub\Exceptions;

use Carbon\CarbonImmutable;

/** GitHub refused the request because a rate limit was exceeded. Retry after {@see $retryAt}. */
class GitHubRateLimitedException extends GitHubException
{
    /**
     * Capture the GitHub retry time and a technical exception message.
     *
     * @param  CarbonImmutable  $retryAt  Time when GitHub permits another request.
     * @param  string  $message  Technical message used for diagnostics.
     */
    public function __construct(public readonly CarbonImmutable $retryAt, string $message = 'GitHub rate limit exceeded.')
    {
        parent::__construct($message);
    }

    /**
     * Explain that synchronization is waiting for GitHub's rate limit to reset.
     *
     * @return string User-safe rate-limit message.
     */
    public function userMessage(): string
    {
        return 'GitHub rate limit reached. The synchronization will be retried automatically.';
    }
}
