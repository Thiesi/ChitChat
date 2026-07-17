<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\RateLimitPolicySet;

final class RateLimiterTest extends DatabaseTestCase
{
    public function testLimitIsAtomicPerPolicyAndIdentifier(): void
    {
        $policies = RateLimitPolicySet::defaults()->with('room_send', 2, 60);
        $limiter = new RateLimiter($this->pdo, $policies);
        $limiter->consume('room_send', 'user:1');
        $limiter->consume('room_send', 'user:1');
        $limiter->consume('room_send', 'user:2');
        $limiter->consume('room_ping', 'user:1');

        try {
            $limiter->consume('room_send', 'user:1');
            self::fail('Expected rate-limit rejection.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('rate_limited', $exception->errorCode);
        }

        self::assertSame(
            3,
            (int) $this->pdo->query(
                "SELECT attempt_count FROM request_rate_limits WHERE scope = 'room_send' ORDER BY attempt_count DESC LIMIT 1",
            )->fetchColumn(),
        );
        self::assertSame(
            ['allowed_count' => 3, 'rejected_count' => 1],
            $this->decisionCounts('room_send'),
        );
        self::assertSame(
            ['allowed_count' => 1, 'rejected_count' => 0],
            $this->decisionCounts('room_ping'),
        );
    }

    public function testExpiredWindowResetsCounter(): void
    {
        $policies = RateLimitPolicySet::defaults()->with('registration', 1, 60);
        $limiter = new RateLimiter($this->pdo, $policies);
        $limiter->consume('registration', 'ip:127.0.0.1');
        $this->pdo->exec(
            "UPDATE request_rate_limits SET window_started_at = NOW() - INTERVAL '2 minutes'",
        );

        $limiter->consume('registration', 'ip:127.0.0.1');

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT attempt_count FROM request_rate_limits')->fetchColumn(),
        );
        self::assertSame(
            ['allowed_count' => 2, 'rejected_count' => 0],
            $this->decisionCounts('registration'),
        );
    }

    public function testExternalDecisionRecordingStoresNoIdentifier(): void
    {
        $limiter = new RateLimiter($this->pdo, RateLimitPolicySet::defaults());
        $limiter->recordDecision('login', true);
        $limiter->recordDecision('login', false);

        self::assertSame(
            ['allowed_count' => 1, 'rejected_count' => 1],
            $this->decisionCounts('login'),
        );
        self::assertSame(
            ['scope', 'allowed_count', 'rejected_count', 'last_allowed_at', 'last_rejected_at', 'updated_at'],
            $this->tableColumns('rate_limit_counters'),
        );
    }

    /** @return array{allowed_count:int, rejected_count:int} */
    private function decisionCounts(string $scope): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT allowed_count, rejected_count
FROM rate_limit_counters
WHERE scope = :scope
SQL);
        $statement->execute(['scope' => $scope]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return [
            'allowed_count' => (int) $row['allowed_count'],
            'rejected_count' => (int) $row['rejected_count'],
        ];
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT column_name
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = :table
ORDER BY ordinal_position
SQL);
        $statement->execute(['table' => $table]);
        $columns = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $columns));
    }
}
