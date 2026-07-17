<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;

final class RateLimiterTest extends DatabaseTestCase
{
    public function testLimitIsAtomicPerScopeAndIdentifier(): void
    {
        $limiter = new RateLimiter($this->pdo);
        $limiter->consume('message_send', 'user:1', 2, 60);
        $limiter->consume('message_send', 'user:1', 2, 60);
        $limiter->consume('message_send', 'user:2', 2, 60);
        $limiter->consume('other_scope', 'user:1', 2, 60);

        try {
            $limiter->consume('message_send', 'user:1', 2, 60);
            self::fail('Expected rate-limit rejection.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('rate_limited', $exception->errorCode);
        }

        self::assertSame(
            3,
            (int) $this->pdo->query(
                "SELECT attempt_count FROM request_rate_limits WHERE scope = 'message_send' ORDER BY attempt_count DESC LIMIT 1",
            )->fetchColumn(),
        );
    }

    public function testExpiredWindowResetsCounter(): void
    {
        $limiter = new RateLimiter($this->pdo);
        $limiter->consume('registration', 'ip:127.0.0.1', 1, 60);
        $this->pdo->exec(
            "UPDATE request_rate_limits SET window_started_at = NOW() - INTERVAL '2 minutes'",
        );

        $limiter->consume('registration', 'ip:127.0.0.1', 1, 60);

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT attempt_count FROM request_rate_limits')->fetchColumn(),
        );
    }
}
