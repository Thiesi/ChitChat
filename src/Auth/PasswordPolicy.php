<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;

final class PasswordPolicy
{
    public static function validate(string $password, string $username): void
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < 12) {
            throw new ApiException(400, 'weak_password', 'Password must contain at least 12 characters.');
        }

        if ($length > 4096) {
            throw new ApiException(400, 'weak_password', 'Password is too long.');
        }

        if (str_contains(mb_strtolower($password, 'UTF-8'), mb_strtolower($username, 'UTF-8'))) {
            throw new ApiException(400, 'weak_password', 'Password must not contain the username.');
        }
    }
}
