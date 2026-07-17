<?php

declare(strict_types=1);

namespace ChitChat\Http;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimitPolicySet $policies,
    ) {
    }

    public function consume(string $policyName, string $identifier): void
    {
        $policy = $this->policies->get($policyName);
        if ($identifier === '') {
            throw new RuntimeException('Rate-limit identifier is invalid.');
        }

        $now = new DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d seconds', $policy->windowSeconds));
        $statement = $this->pdo->prepare(<<<'SQL'
WITH updated_limit AS (
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
), counted AS (
    INSERT INTO rate_limit_counters (
        scope,
        allowed_count,
        rejected_count,
        last_allowed_at,
        last_rejected_at,
        updated_at
    )
    SELECT
        :counter_scope,
        CASE WHEN attempt_count <= CAST(:maximum_allowed AS integer) THEN 1 ELSE 0 END,
        CASE WHEN attempt_count <= CAST(:maximum_rejected AS integer) THEN 0 ELSE 1 END,
        CASE WHEN attempt_count <= CAST(:maximum_allowed_at AS integer) THEN NOW() ELSE NULL END,
        CASE WHEN attempt_count <= CAST(:maximum_rejected_at AS integer) THEN NULL ELSE NOW() END,
        NOW()
    FROM updated_limit
    ON CONFLICT (scope) DO UPDATE
    SET allowed_count = rate_limit_counters.allowed_count + EXCLUDED.allowed_count,
        rejected_count = rate_limit_counters.rejected_count + EXCLUDED.rejected_count,
        last_allowed_at = COALESCE(EXCLUDED.last_allowed_at, rate_limit_counters.last_allowed_at),
        last_rejected_at = COALESCE(EXCLUDED.last_rejected_at, rate_limit_counters.last_rejected_at),
        updated_at = NOW()
)
SELECT attempt_count, window_started_at
FROM updated_limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare rate-limit update.');
        }
        $statement->execute([
            'scope' => $policy->name,
            'identifier_hash' => hash('sha256', $identifier),
            'cutoff_count' => $cutoff->format(DATE_ATOM),
            'cutoff_window' => $cutoff->format(DATE_ATOM),
            'counter_scope' => $policy->name,
            'maximum_allowed' => $policy->maximumAttempts,
            'maximum_rejected' => $policy->maximumAttempts,
            'maximum_allowed_at' => $policy->maximumAttempts,
            'maximum_rejected_at' => $policy->maximumAttempts,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Rate-limit update did not return a result.');
        }

        $attempts = (int) $row['attempt_count'];
        if ($attempts <= $policy->maximumAttempts) {
            return;
        }

        $startedAt = new DateTimeImmutable((string) $row['window_started_at']);
        $retryAfter = max(1, $startedAt->getTimestamp() + $policy->windowSeconds - $now->getTimestamp());
        throw new ApiException(
            429,
            'rate_limited',
            sprintf('Too many requests. Try again in approximately %d seconds.', $retryAfter),
        );
    }

    public function recordDecision(string $policyName, bool $allowed): void
    {
        $policy = $this->policies->get($policyName);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO rate_limit_counters (
    scope,
    allowed_count,
    rejected_count,
    last_allowed_at,
    last_rejected_at,
    updated_at
)
VALUES (
    :scope,
    :allowed_count,
    :rejected_count,
    CASE WHEN CAST(:allowed_at AS integer) = 1 THEN NOW() ELSE NULL END,
    CASE WHEN CAST(:rejected_at AS integer) = 1 THEN NOW() ELSE NULL END,
    NOW()
)
ON CONFLICT (scope) DO UPDATE
SET allowed_count = rate_limit_counters.allowed_count + EXCLUDED.allowed_count,
    rejected_count = rate_limit_counters.rejected_count + EXCLUDED.rejected_count,
    last_allowed_at = COALESCE(EXCLUDED.last_allowed_at, rate_limit_counters.last_allowed_at),
    last_rejected_at = COALESCE(EXCLUDED.last_rejected_at, rate_limit_counters.last_rejected_at),
    updated_at = NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare rate-limit outcome update.');
        }
        $statement->execute([
            'scope' => $policy->name,
            'allowed_count' => $allowed ? 1 : 0,
            'rejected_count' => $allowed ? 0 : 1,
            'allowed_at' => $allowed ? 1 : 0,
            'rejected_at' => $allowed ? 0 : 1,
        ]);
    }
}
