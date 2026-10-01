<?php

namespace App\Integrations\GitHub\Exceptions;

use RuntimeException;

/**
 * Base class for everything that can go wrong while talking to GitHub.
 *
 * The exception message is meant for logs (it may contain technical detail);
 * {@see userMessage()} is the text that is safe to show in the interface.
 */
class GitHubException extends RuntimeException
{
    public function userMessage(): string
    {
        return 'GitHub returned an unexpected response.';
    }
}
