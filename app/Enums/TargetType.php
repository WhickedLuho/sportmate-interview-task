<?php

namespace App\Enums;

enum TargetType: string
{
    case User = 'user';
    case Organization = 'organization';

    /** Map the `type` field of the GitHub API (`User` / `Organization`). */
    public static function fromGitHub(string $type): ?self
    {
        return match (strtolower($type)) {
            'user' => self::User,
            'organization' => self::Organization,
            default => null,
        };
    }
}
