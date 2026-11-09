<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Account\PrivacyNotificationService;
use Closure;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** @phpstan-import-type SubscriptionData from PushTransport */
final class PushDeliveryQueue
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function now(): DateTimeImmutable
    {
        $value = $this->query('SELECT clock_timestamp()')->fetchColumn();
        if (!is_string($value)) {
            throw new RuntimeException('Unable to read push queue time.');
        }
        return new DateTimeImmutable($value);
    }
    /** @param SubscriptionData $subscription */
    public static function fingerprint(array $subscription): string
    {
        return hash('sha256', json_encode($subscription, JSON_THROW_ON_ERROR));
    }

    /**
     * @param Closure(PushNotification):?string $suppression
     * @return array<string,int>
     */
    public function materialize(int $limit, DateTimeImmutable $now, Closure $suppression): array
    {
        $counts = ['processed' => 0];
        for ($index = 0; $index < $limit; $index++) {
            $this->pdo->beginTransaction();
            try {
                $row = $this->query(<<<'SQL'
SELECT n.id, n.user_id, n.kind, n.context_json::text AS context, n.created_at, n.read_at
FROM account_notifications n
WHERE n.push_dispatched_at IS NULL AND n.kind <> 'privacy_policy_updated'
  AND NOT EXISTS (SELECT 1 FROM push_dispatch_sources source WHERE source.notification_id = n.id)
ORDER BY n.id LIMIT 1 FOR UPDATE OF n SKIP LOCKED
SQL)->fetch();
                if (!is_array($row)) {
                    $this->pdo->commit();
                    break;
                }
                $notification = $this->notification($row);
                $deadline = $notification->createdAt->modify('+' . PushDeliveryPolicy::lifetime($notification->kind) . ' seconds');
                $reason = $notification->read ? 'read' : ($deadline <= $now ? 'expired' : $suppression($notification));
                if ($reason !== null) {
                    $this->markSource($notification->id, $reason, $now);
                    $counts['skipped_' . $reason] = ($counts['skipped_' . $reason] ?? 0) + 1;
                } else {
                    $subscriptions = $this->query(<<<'SQL'
SELECT id, user_id, endpoint, p256dh_key, auth_key FROM push_subscriptions
WHERE user_id = :user AND created_at <= :created ORDER BY id
SQL, ['user' => $notification->userId, 'created' => $row['created_at']])->fetchAll();
                    if ($subscriptions === []) {
                        $this->markSource($notification->id, 'no_subscriptions', $now);
                        $counts['skipped_no_subscriptions'] = ($counts['skipped_no_subscriptions'] ?? 0) + 1;
                    } else {
                        $jobId = (int) $this->query(<<<'SQL'
INSERT INTO push_dispatch_jobs (user_id, event_key, expires_at, created_at)
VALUES (:user, :event, :expires, :created) RETURNING id
SQL, ['user' => $notification->userId, 'event' => 'notification:' . $notification->id,
                            'expires' => self::time($deadline), 'created' => self::time($now)])->fetchColumn();
                        $this->query('INSERT INTO push_dispatch_sources (notification_id, job_id) VALUES (:source, :job)',
                            ['source' => $notification->id, 'job' => $jobId]);
                        foreach ($subscriptions as $subscriptionRow) {
                            $subscription = self::subscription($subscriptionRow);
                            $this->query(<<<'SQL'
INSERT INTO push_deliveries (job_id, subscription_id, subscription_fingerprint, next_attempt_at)
VALUES (:job, :subscription, :fingerprint, :due)
SQL, ['job' => $jobId, 'subscription' => $subscription['id'],
                                'fingerprint' => self::fingerprint($subscription), 'due' => self::time($now)]);
                        }
                    }
                }
                $counts['processed']++;
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
        return $counts;
    }

    /** One event is leased to one worker, for one device attempt at a time. */
    public function claim(DateTimeImmutable $now): ?PushClaim
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->query(<<<'SQL'
SELECT j.id, j.expires_at
FROM push_dispatch_jobs j
WHERE j.finished_at IS NULL AND (j.lease_until IS NULL OR j.lease_until <= :lease_now)
  AND EXISTS (
      SELECT 1 FROM push_deliveries d WHERE d.job_id = j.id AND d.state = 'pending'
        AND (d.next_attempt_at <= :due_now OR j.expires_at <= :expiry_now)
  )
ORDER BY j.id LIMIT 1 FOR UPDATE OF j SKIP LOCKED
SQL, ['lease_now' => self::time($now), 'due_now' => self::time($now), 'expiry_now' => self::time($now)])->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $jobId = (int) $job['id'];
            $source = $this->query(<<<'SQL'
SELECT n.id, n.user_id, n.kind, n.context_json::text AS context, n.created_at, n.read_at
FROM push_dispatch_sources source JOIN account_notifications n ON n.id = source.notification_id
WHERE source.job_id = :job ORDER BY n.id LIMIT 1
SQL, ['job' => $jobId])->fetch();
            if (!is_array($source)) {
                $this->query('DELETE FROM push_dispatch_jobs WHERE id = :id', ['id' => $jobId]);
                $this->pdo->commit();
                return null;
            }
            $delivery = $this->query(<<<'SQL'
SELECT d.id, d.subscription_fingerprint, d.attempts,
       s.id AS subscription_id, s.user_id, s.endpoint, s.p256dh_key, s.auth_key
FROM push_deliveries d LEFT JOIN push_subscriptions s ON s.id = d.subscription_id
WHERE d.job_id = :job AND d.state = 'pending'
  AND (d.next_attempt_at <= :due OR :expired::timestamptz >= :deadline::timestamptz)
ORDER BY d.id LIMIT 1
SQL, ['job' => $jobId, 'due' => self::time($now), 'expired' => self::time($now),
                'deadline' => $job['expires_at']])->fetch();
            if (!is_array($delivery)) {
                throw new RuntimeException('Push delivery claim has no due device.');
            }
            $token = bin2hex(random_bytes(32));
            $lease = self::time($now->modify('+' . PushDeliveryPolicy::LEASE_SECONDS . ' seconds'));
            $this->query('UPDATE push_dispatch_jobs SET claim_token = :token, lease_until = :lease WHERE id = :job',
                ['token' => $token, 'lease' => $lease, 'job' => $jobId]);
            // Claiming consumes an attempt, including a worker crash with uncertain provider acceptance.
            $attempts = min(PushDeliveryPolicy::MAX_ATTEMPTS, (int) $delivery['attempts'] + 1);
            $this->query('UPDATE push_deliveries SET attempts = :attempts, next_attempt_at = :due WHERE id = :id',
                ['attempts' => $attempts, 'due' => $lease, 'id' => $delivery['id']]);
            $subscription = $delivery['subscription_id'] === null ? null
                : self::subscription(array_replace($delivery, ['id' => $delivery['subscription_id']]));
            $claim = new PushClaim($jobId, (int) $delivery['id'], $token,
                (int) $delivery['attempts'] + 1, new DateTimeImmutable((string) $job['expires_at']),
                $this->notification($source), $subscription, (string) $delivery['subscription_fingerprint']);
            $this->pdo->commit();
            return $claim;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** Returns null for a stale worker; no stale result may mutate the ledger or a rotated device. */
    public function finish(PushClaim $claim, string $state, string $outcome, DateTimeImmutable $now,
        ?int $status = null, ?DateTimeImmutable $retryAt = null, bool $pruneSubscription = false): ?int
    {
        $this->pdo->beginTransaction();
        try {
            $owned = $this->query(<<<'SQL'
SELECT id FROM push_dispatch_jobs
WHERE id = :id AND claim_token = :token AND lease_until > :now AND finished_at IS NULL
FOR UPDATE
SQL, ['id' => $claim->jobId, 'token' => $claim->token, 'now' => self::time($now)])->fetchColumn();
            if ($owned === false) {
                $this->pdo->commit();
                return null;
            }
            $this->query(<<<'SQL'
UPDATE push_deliveries SET state = :state, outcome = :outcome, http_status = :status,
    completed_at = :completed, next_attempt_at = :next
WHERE id = :id AND job_id = :job
SQL, ['state' => $state, 'outcome' => $state === 'pending' ? null : $outcome, 'status' => $status,
                'completed' => $state === 'pending' ? null : self::time($now),
                'next' => self::time($retryAt ?? $now), 'id' => $claim->deliveryId, 'job' => $claim->jobId]);
            $pruned = 0;
            if ($pruneSubscription && $claim->subscription !== null) {
                $subscription = $claim->subscription;
                $pruned = $this->query(<<<'SQL'
DELETE FROM push_subscriptions
WHERE id = :id AND user_id = :user AND endpoint = :endpoint AND p256dh_key = :p256dh AND auth_key = :auth
SQL, ['id' => $subscription['id'], 'user' => $subscription['user_id'], 'endpoint' => $subscription['endpoint'],
                    'p256dh' => $subscription['p256dh_key'], 'auth' => $subscription['auth_key']])->rowCount();
            }
            $this->query('UPDATE push_dispatch_jobs SET claim_token = NULL, lease_until = NULL WHERE id = :id', ['id' => $claim->jobId]);
            $pending = (int) $this->query("SELECT COUNT(*) FROM push_deliveries WHERE job_id = :job AND state = 'pending'",
                ['job' => $claim->jobId])->fetchColumn();
            if ($pending === 0) {
                $failed = (int) $this->query("SELECT COUNT(*) FROM push_deliveries WHERE job_id = :job AND state = 'failed'",
                    ['job' => $claim->jobId])->fetchColumn();
                $sent = (int) $this->query("SELECT COUNT(*) FROM push_deliveries WHERE job_id = :job AND state = 'sent'",
                    ['job' => $claim->jobId])->fetchColumn();
                $jobOutcome = $failed > 0 ? ($sent > 0 ? 'partial_failure' : 'failed') : ($sent > 0 ? 'sent' : $outcome);
                $this->query('UPDATE push_dispatch_jobs SET finished_at = :now, outcome = :outcome WHERE id = :job',
                    ['now' => self::time($now), 'outcome' => $jobOutcome, 'job' => $claim->jobId]);
                $this->query(<<<'SQL'
UPDATE account_notifications SET push_dispatched_at = :now, push_outcome = :outcome
WHERE id IN (SELECT notification_id FROM push_dispatch_sources WHERE job_id = :job)
SQL, ['now' => self::time($now), 'outcome' => $jobOutcome, 'job' => $claim->jobId]);
            }
            $this->pdo->commit();
            return $pruned;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function prune(DateTimeImmutable $now): int
    {
        return $this->query(<<<'SQL'
DELETE FROM push_dispatch_jobs j WHERE finished_at < :cutoff
 OR NOT EXISTS (SELECT 1 FROM push_dispatch_sources source WHERE source.job_id = j.id)
SQL, ['cutoff' => self::time($now->modify('-' . PushDeliveryPolicy::HISTORY_DAYS . ' days'))])->rowCount();
    }

    /** @return array<string,int> */
    public function health(DateTimeImmutable $now): array
    {
        $row = $this->query(<<<'SQL'
SELECT COUNT(*) FILTER (WHERE d.state = 'pending') AS pending_deliveries,
       COUNT(*) FILTER (WHERE d.state = 'pending' AND d.next_attempt_at <= :now) AS due_deliveries,
       COUNT(*) FILTER (WHERE d.state = 'failed') AS failed_deliveries,
       COALESCE(EXTRACT(EPOCH FROM (:age_now::timestamptz - MIN(j.created_at) FILTER (WHERE d.state = 'pending')))::integer, 0) AS oldest_pending_seconds
FROM push_deliveries d JOIN push_dispatch_jobs j ON j.id = d.job_id
SQL, ['now' => self::time($now), 'age_now' => self::time($now)])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Unable to read push queue health.');
        }
        return array_map(static fn (mixed $value): int => (int) $value, $row);
    }

    /** @param array<string,mixed> $row */
    private function notification(array $row): PushNotification
    {
        return new PushNotification((int) $row['id'], (int) $row['user_id'], (string) $row['kind'],
            (new PrivacyNotificationService($this->pdo))->decodeContext((string) $row['context']),
            new DateTimeImmutable((string) $row['created_at']), $row['read_at'] !== null);
    }

    /**
     * @param array<string,mixed> $row
     * @return SubscriptionData
     */
    private static function subscription(array $row): array
    {
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'],
            'endpoint' => (string) $row['endpoint'], 'p256dh_key' => (string) $row['p256dh_key'],
            'auth_key' => (string) $row['auth_key']];
    }

    private function markSource(int $id, string $reason, DateTimeImmutable $now): void
    {
        $this->query('UPDATE account_notifications SET push_dispatched_at = :now, push_outcome = :reason WHERE id = :id',
            ['now' => self::time($now), 'reason' => $reason, 'id' => $id]);
    }

    /** @param array<string,mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push delivery query.');
        }
        $statement->execute($parameters);
        return $statement;
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
