<?php

declare(strict_types=1);
namespace ChitChat\Auth;

/**
 * The one place ChitChat hashes passwords (#163).
 *
 * In production this is exactly `password_hash($password, PASSWORD_DEFAULT)` and
 * `password_needs_rehash($hash, PASSWORD_DEFAULT)`, with PHP's default options. No
 * configuration or environment variable can change that. Only the PHPUnit bootstrap lowers
 * the cost, through useMinimumCostInTestsOnly(), and only in its own process.
 */
final class PasswordHasher
{
    /** @var array<string, int> password_hash options; empty means PHP's defaults */
    private static array $options = [];

    public static function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, self::$options);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, self::$options);
    }

    /**
     * Test-only hook: bcrypt's minimum cost for the rest of this process.
     *
     * @internal Called only by tests/bootstrap.php. PasswordHasherTest fails if any file
     *           outside tests/ calls it. Never call it from public/, bin/ or src/.
     */
    public static function useMinimumCostInTestsOnly(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('The minimum password cost is for the test suite only.');
        }
        self::$options = ['cost' => 4];
    }
}
