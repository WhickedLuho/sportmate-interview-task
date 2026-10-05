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
    /**
     * Provide a user-safe message without exposing technical exception details.
     *
     * @return string Message suitable for the target's latest error and UI.
     */
    public function userMessage(): string
    {
        return 'GitHub returned an unexpected response.';
    }
}
