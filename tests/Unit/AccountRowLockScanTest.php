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
            // avatar_key (#262): upload and self-removal change only the actor's own row; a moderator's
            // removal locks through AccountLocks::forKeyChange; clearForClosure runs inside closure
            // finalization, which already tombstones the row and has no staff actor.
            'src/Account/AvatarService.php' => 3,
        ],
    ];

    /**
     * Columns of a non-partial, non-expression unique index (the primary key included) on users.
     * PostgreSQL treats an UPDATE of any of them as a key update at FOR UPDATE strength.
     * AccountKeyColumnsTest checks this list against pg_index, so a new UNIQUE column fails there.
     */
    public const KEY_COLUMNS = ['id', 'username_canonical', 'avatar_key'];

    /** @return array<string, string> */
    private static function patterns(): array
    {
        return [
            'users row FOR UPDATE' => '/FROM\s+users\b[^;]{0,600}?(?<!NO KEY )FOR\s+UPDATE\b(?!\s+SKIP)/s',
            'FOR UPDATE OF users alias' => '/FOR\s+UPDATE\s+OF\s+[\w\s,]*\bu\b/',
            // A key column as the first assignment after SET, or after a comma before WHERE.
            'key-changing UPDATE users' => '/UPDATE\s+users\b(?:\s+u)?\s+SET\s+(?:(?:(?!\bWHERE\b).){0,800}?,\s*)?(?:'
                . implode('|', self::KEY_COLUMNS) . ')\s*=/s',
        ];
    }

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
            foreach (self::patterns() as $name => $pattern) {
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
            'users row FOR UPDATE' => [["SELECT avatar_key FROM users WHERE id = :id FOR UPDATE"], ["SELECT avatar_key FROM users WHERE id = :id FOR NO KEY UPDATE"]],
            'FOR UPDATE OF users alias' => [["FROM account_closures ac JOIN users u ON u.id = ac.user_id FOR UPDATE OF ac, u"], ["FOR UPDATE OF ac"]],
            'key-changing UPDATE users' => [
                [
                    "UPDATE users SET username = :u, username_canonical = :c WHERE id = :id",
                    "UPDATE users SET avatar_key = NULL, updated_at = NOW() WHERE id = :id",
                    "UPDATE users SET avatar_updated_at = NOW(),\n    avatar_key = :key WHERE id = :id",
                    "UPDATE users u SET id = :new WHERE id = :old",
                ],
                [
                    "UPDATE users SET avatar_updated_at = NOW(), updated_at = NOW() WHERE id = :id",
                    "UPDATE users SET share_typing = NOT share_typing WHERE id = :id AND avatar_key = :key",
                ],
            ],
        ];
        $patterns = self::patterns();
        foreach ($samples as $name => [$matching, $harmless]) {
            foreach ($matching as $sample) {
                self::assertSame(1, preg_match($patterns[$name], $sample), $name . ' must match: ' . $sample);
            }
            foreach ($harmless as $sample) {
                self::assertSame(0, preg_match($patterns[$name], $sample), $name . ' must not match: ' . $sample);
            }
        }
    }
}
