<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Tests\Unit\AccountRowLockScanTest;
use PDO;

/**
 * The account-row lock scan (#248, #262) recognises key changes by column name.
 * PostgreSQL treats every column of a non-partial, non-expression unique index
 * on users (the primary key included) as a key, so a new UNIQUE column must be
 * added to AccountRowLockScanTest::KEY_COLUMNS and its writers reviewed.
 */
final class AccountKeyColumnsTest extends DatabaseTestCase
{
    public function testTheScannedKeyColumnsAreExactlyTheUsersKeyColumns(): void
    {
        $columns = $this->pdo->query(<<<'SQL'
            SELECT DISTINCT a.attname
            FROM pg_index x
            JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = ANY (x.indkey)
            WHERE x.indrelid = 'users'::regclass
              AND x.indisunique
              AND x.indpred IS NULL
              AND x.indexprs IS NULL
            ORDER BY a.attname
            SQL)?->fetchAll(PDO::FETCH_COLUMN);
        self::assertIsArray($columns);
        $expected = AccountRowLockScanTest::KEY_COLUMNS;
        sort($expected);
        self::assertSame($expected, $columns, 'A new key column on users needs its writers reviewed in AccountRowLockScanTest (docs/architecture/0007-account-row-locks.md).');
    }
}
