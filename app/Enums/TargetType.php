<?php

namespace App\Enums;

enum TargetType: string
{
    case User = 'user';
    case Organization = 'organization';

    /**
     * Map GitHub's owner type to the local account-type enum.
     *
     * @param  string  $type  Owner type supplied by GitHub, matched case-insensitively.
     *
     * @return self|null Recognized user or organization type, or null if unknown.
     */
    public static function fromGitHub(string $type): ?self
    {
        return match (strtolower($type)) {
            'user' => self::User,
            'organization' => self::Organization,
            default => null,
        };
    }
}
