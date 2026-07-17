<?php

declare(strict_types=1);

namespace ChitChat\Http;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function consume(
        string $scope,
        string $identifier,
        int $maximumAttempts,
        int $windowSeconds,
    ): void {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/D', $scope) !== 1) {
            throw new RuntimeException('Rate-limit scope is invalid.');
        }
        if ($identifier === '' || $maximumAttempts < 1 || $windowSeconds < 1) {
            throw new RuntimeException('Rate-limit configuration is invalid.');
        }

        $now = new DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d seconds', $windowSeconds));
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO request_rate_limits (
    scope,
    identifier_hash,
    window_started_at,
    attempt_count,
    updated_at
)
VALUES (
    :scope,
    :identifier_hash,
    NOW(),
    1,
    NOW()
)
ON CONFLICT (scope, identifier_hash) DO UPDATE
SET attempt_count = CASE
        WHEN request_rate_limits.window_started_at <= CAST(:cutoff_count AS timestamptz) THEN 1
        ELSE request_rate_limits.attempt_count + 1
    END,
    window_started_at = CASE
        WHEN request_rate_limits.window_started_at <= CAST(:cutoff_window AS timestamptz) THEN NOW()
        ELSE request_rate_limits.window_started_at
    END,
    updated_at = NOW()
RETURNING attempt_count, window_started_at
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare rate-limit update.');
        }
        $statement->execute([
            'scope' => $scope,
            'identifier_hash' => hash('sha256', $identifier),
            'cutoff_count' => $cutoff->format(DATE_ATOM),
            'cutoff_window' => $cutoff->format(DATE_ATOM),
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Rate-limit update did not return a result.');
        }

        $attempts = (int) $row['attempt_count'];
        if ($attempts <= $maximumAttempts) {
            return;
        }

        $startedAt = new DateTimeImmutable((string) $row['window_started_at']);
        $retryAfter = max(1, $startedAt->getTimestamp() + $windowSeconds - $now->getTimestamp());
        throw new ApiException(
            429,
            'rate_limited',
            sprintf('Too many requests. Try again in approximately %d seconds.', $retryAfter),
        );
    }
}
