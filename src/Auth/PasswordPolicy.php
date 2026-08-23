<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;

final class PasswordPolicy
{
    /**
     * A fixed, valid bcrypt hash of an unrelated constant string, used to keep
     * password_verify() timing indistinguishable between "account not found"
     * and "account found, password wrong" so login/restore responses cannot be
     * used to enumerate valid usernames via a timing side channel.
     */
    public const DUMMY_PASSWORD_HASH = '$2y$12$0GZxNt3X00ml05PSIYqCNOZucqTykUWDc1Jt2etQIcicXnuBfXriW';

    public static function validate(string $password, string $username): void
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < 12) {
            throw new ApiException(400, 'weak_password', 'Password must contain at least 12 characters.');
        }

        if ($length > 4096) {
            throw new ApiException(400, 'weak_password', 'Password is too long.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $password) === 1) {
            throw new ApiException(400, 'weak_password', 'Password must not contain control characters.');
        }

        if (str_contains(mb_strtolower($password, 'UTF-8'), mb_strtolower($username, 'UTF-8'))) {
            throw new ApiException(400, 'weak_password', 'Password must not contain the username.');
        }
    }
}
