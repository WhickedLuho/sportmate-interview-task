<?php

namespace App\Integrations\GitHub\Exceptions;

/** The user or organization does not exist (or has no public profile). Retrying will not help. */
class GitHubNotFoundException extends GitHubException
{
    /**
     * Explain that GitHub could not find the requested account.
     *
     * @return string User-safe account-not-found message.
     */
    public function userMessage(): string
    {
        return 'This GitHub user or organization was not found.';
    }
}
