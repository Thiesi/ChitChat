<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;

final class Username
{
    public static function display(string $username): string
    {
        $username = trim($username);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,31}\z/D', $username) !== 1) {
            throw new ApiException(
                400,
                'invalid_username',
                'Username must be 3-32 ASCII characters and may contain letters, numbers, dots, underscores, and hyphens.',
            );
        }

        return $username;
    }

    public static function canonical(string $username): string
    {
        return strtolower(self::display($username));
    }
}
