<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Room\ModerationRank;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Global ranks are defined once; SQL is generated from them (#302), so PHP and SQL agree. */
final class GlobalRankCaseTest extends TestCase
{
    public function testEveryRoleIsInTheSqlWithItsRankAndAnythingElseIsZero(): void
    {
        $sql = ModerationRank::globalRankCase('ur.role');
        self::assertStringStartsWith('CASE ur.role ', $sql);
        self::assertStringEndsWith(' ELSE 0 END', $sql);
        self::assertNotSame([], ModerationRank::GLOBAL_RANKS);
        foreach (ModerationRank::GLOBAL_RANKS as $role => $rank) {
            self::assertStringContainsString(" WHEN '{$role}' THEN {$rank} ", $sql . ' ', $role);
            // And the PHP side gives the same rank for that role.
            self::assertSame($rank, ModerationRank::globalRankOf(new AuthenticatedUser(1, 'Someone', [$role], 1)), $role);
        }
        self::assertSame(count(ModerationRank::GLOBAL_RANKS), substr_count($sql, ' WHEN '), 'No other cases.');
    }

    public function testOnlyAPlainColumnNameIsAccepted(): void
    {
        self::assertSame('CASE role', substr(ModerationRank::globalRankCase('role'), 0, 9));
        $this->expectException(RuntimeException::class);
        ModerationRank::globalRankCase("role) OR (1=1");
    }
}
