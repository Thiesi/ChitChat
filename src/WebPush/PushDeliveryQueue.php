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
        $identity = ['id' => $subscription['id'], 'user_id' => $subscription['user_id'],
            'endpoint' => $subscription['endpoint'], 'p256dh_key' => $subscription['p256dh_key'],
            'auth_key' => $subscription['auth_key']];
        // Preserve already-queued legacy fingerprints during the additive migration.
        // Binding a registration changes its identity and fences the old delivery.
        if (isset($subscription['delivery_binding'])) {
            $identity += ['session_version' => $subscription['session_version'] ?? 0,
                'delivery_binding' => $subscription['delivery_binding'], 'generation' => $subscription['generation'] ?? 1];
        }
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
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
  AND NOT EXISTS (SELECT 1 FROM push_dispatch_sources s WHERE s.notification_id = n.id)
ORDER BY n.id LIMIT 1 FOR UPDATE OF n SKIP LOCKED
SQL)->fetch();
                if (!is_array($row)) {
                    $this->pdo->commit();
                    break;
                }
                if ($this->query('SELECT 1 FROM push_dispatch_sources WHERE notification_id = :id',
                    ['id' => $row['id']])->fetchColumn() !== false) {
                    $this->pdo->commit();
                    continue;
                }
                $candidate = $this->notification($row);
                $messageId = self::directMessageId($candidate);
                $eventKey = $messageId === null ? 'notification:' . $candidate->id : 'direct:' . $messageId;
                $sources = [$candidate];
                if ($messageId !== null) {
                    // Another worker may own the sibling notification. Never wait
                    // for the group lock while retaining a different source lock.
                    $locked = $this->query('SELECT pg_try_advisory_xact_lock(hashtextextended(:event, 0))',
                        ['event' => 'push:' . $candidate->userId . ':' . $eventKey])->fetchColumn();
                    if (!in_array($locked, [true, 't', 1, '1'], true)) {
                        $this->pdo->commit();
                        continue;
                    }
                    $rows = $this->query(<<<'SQL'
SELECT n.id, n.user_id, n.kind, n.context_json::text AS context, n.created_at, n.read_at
FROM account_notifications n
WHERE n.user_id = :user AND n.kind IN ('mentioned', 'direct_message_received')
 AND n.context_json->>'message_kind' = 'direct'
 AND n.context_json->>'message_id' = :message
 AND jsonb_typeof(n.context_json->'message_id') = 'number'
 AND n.push_dispatched_at IS NULL
 AND NOT EXISTS (SELECT 1 FROM push_dispatch_sources s WHERE s.notification_id = n.id)
ORDER BY n.id FOR UPDATE OF n
SQL, ['user' => $candidate->userId, 'message' => (string) $messageId])->fetchAll();
                    $sources = array_map(fn (array $source): PushNotification => $this->notification($source), $rows);
                }
                if ($sources === []) {
                    $this->pdo->commit();
                    continue;
                }
                $deadline = $sources[0]->createdAt->modify('+' . PushDeliveryPolicy::lifetime($sources[0]->kind) . ' seconds');
                $selection = $this->selectSource(array_values($sources), $suppression);
                $notification = $selection['notification'];
                $reason = $deadline <= $now ? 'expired' : $selection['reason'];
                $subscriptions = [];
                if ($reason === null) {
                    $subscriptions = $this->query(<<<'SQL'
SELECT id, user_id, endpoint, p256dh_key, auth_key, session_version, delivery_binding, generation FROM push_subscriptions
WHERE user_id = :user AND generation_started_at <= :created
 AND session_version = (SELECT session_version FROM users WHERE id = push_subscriptions.user_id) ORDER BY id
SQL, ['user' => $notification->userId, 'created' => self::time($sources[0]->createdAt)])->fetchAll();
                    if ($subscriptions === []) {
                        $reason = 'no_subscriptions';
                    }
                }
                if ($reason !== null) {
                    foreach ($sources as $source) {
                        $this->markSource($source->id, $reason, $now);
                    }
                    $counts['skipped_' . $reason] = ($counts['skipped_' . $reason] ?? 0) + count($sources);
                } else {
                    $jobId = (int) $this->query(<<<'SQL'
INSERT INTO push_dispatch_jobs (user_id, event_key, expires_at, created_at, selected_notification_id)
VALUES (:user, :event, :expires, :created, :selected) RETURNING id
SQL, ['user' => $notification->userId, 'event' => $eventKey, 'selected' => $notification->id,
                        'expires' => self::time($deadline), 'created' => self::time($now)])->fetchColumn();
                    foreach ($sources as $source) {
                        $this->query('INSERT INTO push_dispatch_sources (notification_id, job_id) VALUES (:source, :job)',
                            ['source' => $source->id, 'job' => $jobId]);
                    }
                    foreach ($subscriptions as $subscriptionRow) {
                        $subscription = self::subscription($subscriptionRow);
                        $this->query(<<<'SQL'
INSERT INTO push_deliveries (job_id, subscription_id, subscription_fingerprint, next_attempt_at)
VALUES (:job, :subscription, :fingerprint, :due)
SQL, ['job' => $jobId, 'subscription' => $subscription['id'],
                            'fingerprint' => self::fingerprint($subscription), 'due' => self::time($now)]);
                    }
                }
                $counts['processed'] += count($sources);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
        return $counts;
    }

    private static function directMessageId(PushNotification $notification): ?int
    {
        $id = $notification->context['message_id'] ?? null;
        return in_array($notification->kind, ['mentioned', 'direct_message_received'], true)
            && ($notification->context['message_kind'] ?? null) === 'direct' && is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @param non-empty-list<PushNotification> $sources
     * @param Closure(PushNotification):?string $suppression
     * @return array{notification:PushNotification,reason:?string}
     */
    private function selectSource(array $sources, Closure $suppression, bool $frozen = false, ?int $selectedId = null): array
    {
        usort($sources, static fn (PushNotification $a, PushNotification $b): int =>
            (($a->kind === 'mentioned' ? 0 : 1) <=> ($b->kind === 'mentioned' ? 0 : 1)) ?: ($a->id <=> $b->id));
        if ($frozen) {
            foreach ($sources as $source) {
                if ($source->id === $selectedId) {
                    return ['notification' => $source, 'reason' => $source->read ? 'read' : $suppression($source)];
                }
            }
            return ['notification' => $sources[0], 'reason' => 'forbidden'];
        }
        $firstReason = null;
        foreach ($sources as $source) {
            $reason = $source->read ? 'read' : $suppression($source);
            if ($reason === null) {
                return ['notification' => $source, 'reason' => null];
            }
            $firstReason ??= $reason;
        }
        return ['notification' => $sources[0], 'reason' => $firstReason];
    }

    /**
     * Re-select under the existing event lease immediately before HTTP.
     * @param Closure(PushNotification):?string $suppression
     * @return array{claim:PushClaim,reason:?string}|null Null means a stale lease.
     */
    public function prepare(PushClaim $claim, DateTimeImmutable $now, Closure $suppression): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->query(<<<'SQL'
SELECT selected_notification_id, selection_frozen FROM push_dispatch_jobs
WHERE id = :id AND claim_token = :token AND lease_until > :now AND finished_at IS NULL FOR UPDATE
SQL, ['id' => $claim->jobId, 'token' => $claim->token, 'now' => self::time($now)])->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $rows = $this->query(<<<'SQL'
SELECT n.id, n.user_id, n.kind, n.context_json::text AS context, n.created_at, n.read_at
FROM push_dispatch_sources s JOIN account_notifications n ON n.id = s.notification_id
WHERE s.job_id = :job ORDER BY n.id
SQL, ['job' => $claim->jobId])->fetchAll();
            $sources = array_map(fn (array $row): PushNotification => $this->notification($row), $rows);
            if ($sources === []) {
                $selection = ['notification' => $claim->notification, 'reason' => 'forbidden'];
            } else {
                $selection = $this->selectSource(array_values($sources), $suppression,
                    in_array($job['selection_frozen'], [true, 't', 1, '1'], true),
                    $job['selected_notification_id'] === null ? null : (int) $job['selected_notification_id']);
                if ($selection['reason'] === null) {
                    $this->query('UPDATE push_dispatch_jobs SET selected_notification_id = :selected WHERE id = :id',
                        ['selected' => $selection['notification']->id, 'id' => $claim->jobId]);
                }
            }
            $this->pdo->commit();
            return ['claim' => $claim->forNotification($selection['notification']), 'reason' => $selection['reason']];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
    /** One event is leased to one worker, for one device attempt at a time. */
    public function claim(DateTimeImmutable $now): ?PushClaim
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->query(<<<'SQL'
SELECT j.id, j.expires_at, j.event_id::text
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
       s.id AS subscription_id, s.user_id, s.endpoint, s.p256dh_key, s.auth_key, s.session_version, s.delivery_binding, s.generation
FROM push_deliveries d LEFT JOIN push_subscriptions s ON s.id = d.subscription_id
 AND s.session_version = (SELECT session_version FROM users WHERE id = s.user_id)
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
                $this->notification($source), $subscription, (string) $delivery['subscription_fingerprint'], (string) $job['event_id']);
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
            if ($state === 'sent') {
                $this->query(<<<'SQL'
UPDATE push_dispatch_jobs SET selected_notification_id = :selected, selection_frozen = TRUE
WHERE id = :job AND NOT selection_frozen
SQL, ['selected' => $claim->notification->id, 'job' => $claim->jobId]);
            }
            // Subscription/cascade rows precede the ledger, matching revoke/rotation.
            $pruned = 0;
            if ($pruneSubscription && $claim->subscription !== null) {
                $subscription = $claim->subscription;
                $pruned = $this->query(<<<'SQL'
DELETE FROM push_subscriptions
WHERE id = :id AND user_id = :user AND endpoint = :endpoint AND p256dh_key = :p256dh AND auth_key = :auth AND generation = :generation
SQL, ['id' => $subscription['id'], 'user' => $subscription['user_id'], 'endpoint' => $subscription['endpoint'],
                    'p256dh' => $subscription['p256dh_key'], 'auth' => $subscription['auth_key'], 'generation' => $subscription['generation'] ?? 1])->rowCount();
            }
            $this->query(<<<'SQL'
UPDATE push_deliveries SET state = :state, outcome = :outcome, http_status = :status,
    completed_at = :completed, next_attempt_at = :next
WHERE id = :id AND job_id = :job
SQL, ['state' => $state, 'outcome' => $state === 'pending' ? null : $outcome, 'status' => $status,
                'completed' => $state === 'pending' ? null : self::time($now),
                'next' => self::time($retryAt ?? $now), 'id' => $claim->deliveryId, 'job' => $claim->jobId]);
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

    public function hasDueWork(DateTimeImmutable $now): bool
    {
        return $this->query(<<<'SQL'
SELECT 1 FROM push_deliveries d JOIN push_dispatch_jobs j ON j.id = d.job_id
WHERE d.state = 'pending' AND j.finished_at IS NULL
 AND (j.lease_until IS NULL OR j.lease_until <= :lease_now)
 AND (d.next_attempt_at <= :due_now OR j.expires_at <= :expiry_now)
LIMIT 1
SQL, ['lease_now' => self::time($now), 'due_now' => self::time($now), 'expiry_now' => self::time($now)])->fetchColumn() !== false;
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
            'auth_key' => (string) $row['auth_key'], 'session_version' => (int) $row['session_version'],
            'delivery_binding' => $row['delivery_binding'] === null ? null : (string) $row['delivery_binding'],
            'generation' => (int) $row['generation']];
    }

    public function registrationIsCurrent(PushClaim $claim): bool
    {
        if ($claim->subscription === null) {
            return false;
        }
        $row = $this->query(<<<'SQL'
SELECT s.* FROM push_subscriptions s JOIN users u ON u.id = s.user_id
WHERE s.id = :id AND s.session_version = u.session_version
SQL, ['id' => $claim->subscription['id']])->fetch();
        return is_array($row) && hash_equals($claim->fingerprint, self::fingerprint(self::subscription($row)));
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
