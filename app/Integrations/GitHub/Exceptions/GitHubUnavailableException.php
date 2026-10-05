<?php

namespace App\Integrations\GitHub\Exceptions;

/** GitHub could not be reached or answered with a server error. Usually transient, so worth retrying. */
class GitHubUnavailableException extends GitHubException
{
    /**
     * Explain that GitHub is temporarily unavailable.
     *
     * @return string User-safe availability message.
     */
    public function userMessage(): string
    {
        return 'GitHub is currently unavailable. Please try again later.';
    }
}
