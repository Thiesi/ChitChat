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

    /** The neutral name a moderator's "require a new username" assigns (#164); never a new choice. */
    public static function isPlaceholder(string $username): bool
    {
        return preg_match('/\Amember-[a-z2-7]{6}\z/iD', trim($username)) === 1;
    }

    /** `member-` plus six random lowercase base32 characters, unrelated to the account ID. */
    public static function placeholder(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $suffix = '';
        for ($index = 0; $index < 6; $index++) {
            $suffix .= $alphabet[random_int(0, 31)];
        }

        return 'member-' . $suffix;
    }
}
