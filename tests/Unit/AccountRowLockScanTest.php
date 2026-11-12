<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the account-row lock rule (#248, docs/architecture/0007-account-row-locks.md):
 * only key changes take FOR UPDATE on a users row, and staff key changes go
 * through AccountLocks::forKeyChange. Every FOR UPDATE on users and every
 * key-changing UPDATE users must be one of the reviewed sites below. A new
 * one fails this test until it is reviewed and added with its reason.
 */
final class AccountRowLockScanTest extends TestCase
{
    /** @var array<string, array<string, int>> pattern => relative file => occurrences */
    private const REVIEWED = [
        'users row FOR UPDATE' => [
            // Self-only closure request/restore: actor = target, so no actor/target cycle.
            'src/Account/AccountClosureService.php' => 1,
            // Re-reads the target already locked by AccountLocks::forKeyChange.
            'src/Account/UsernameChangeService.php' => 1,
        ],
        'FOR UPDATE OF users alias' => [
            // Closure finalization tombstones (a key change) with no staff actor.
            'src/Account/AccountClosureService.php' => 1,
        ],
        'key-changing UPDATE users' => [
            'src/Account/AccountClosureService.php' => 1,
            'src/Account/UsernameChangeService.php' => 2,
        ],
    ];

    private const PATTERNS = [
        'users row FOR UPDATE' => '/FROM\s+users\b[^;]{0,600}?(?<!NO KEY )FOR\s+UPDATE\b(?!\s+SKIP)/s',
        'FOR UPDATE OF users alias' => '/FOR\s+UPDATE\s+OF\s+[\w\s,]*\bu\b/',
        'key-changing UPDATE users' => '/UPDATE\s+users\b(?:\s+u)?\s+SET\b(?:(?!\bWHERE\b).){0,800}?(?:\busername_canonical\s*=|\bSET\s+id\s*=|,\s*id\s*=)/s',
    ];

    public function testStrongAccountRowLocksAreOnlyTheReviewedSites(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (self::PATTERNS as $name => $pattern) {
                $count = preg_match_all($pattern, $source);
                self::assertNotFalse($count, $name);
                if ($count > 0) {
                    $found[$name][$relative] = $count;
                }
            }
        }
        foreach (self::REVIEWED as $name => $sites) {
            $actual = $found[$name] ?? [];
            ksort($actual);
            ksort($sites);
            self::assertSame($sites, $actual, sprintf(
                'Unreviewed "%s" site(s). Use FOR NO KEY UPDATE unless a key changes; staff key changes lock through AccountLocks::forKeyChange (#248).',
                $name,
            ));
        }
    }

    public function testThePatternsRecogniseTheShapesTheyGuard(): void
    {
        $samples = [
            'users row FOR UPDATE' => ["SELECT avatar_key FROM users WHERE id = :id FOR UPDATE", "SELECT avatar_key FROM users WHERE id = :id FOR NO KEY UPDATE"],
            'FOR UPDATE OF users alias' => ["FROM account_closures ac JOIN users u ON u.id = ac.user_id FOR UPDATE OF ac, u", "FOR UPDATE OF ac"],
            'key-changing UPDATE users' => ["UPDATE users SET username = :u, username_canonical = :c WHERE id = :id", "UPDATE users SET avatar_key = NULL, updated_at = NOW() WHERE id = :id"],
        ];
        foreach ($samples as $name => [$matching, $harmless]) {
            self::assertSame(1, preg_match(self::PATTERNS[$name], $matching), $name . ' must match: ' . $matching);
            self::assertSame(0, preg_match(self::PATTERNS[$name], $harmless), $name . ' must not match: ' . $harmless);
        }
    }
}
