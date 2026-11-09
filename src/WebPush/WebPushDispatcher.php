<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Admin\ApplicationNameService;
use ChitChat\Config;
use Closure;
use DateTimeImmutable;
use DateInterval;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Shared best-effort worker. Claims are committed before HTTP; see ADR 0006. */
final class WebPushDispatcher
{
    private readonly Closure $clock;
    private ?DateTimeImmutable $lastPruneAt = null;
    private readonly PushDeliveryQueue $queue;
    private readonly PushEligibility $eligibility;

    /** @param (Closure():DateTimeImmutable)|null $clock */
    public function __construct(private readonly PDO $pdo, private readonly Config $config,
        private ?PushTransport $transport = null, ?Closure $clock = null)
    {
        $this->queue = new PushDeliveryQueue($pdo);
        $queue = $this->queue;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => $queue->now();
        $this->eligibility = new PushEligibility($pdo);
    }

    /** @return array<string,int> */
    public function dispatch(int $limit = 500, bool $includeHealth = true): array
    {
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('limit must be between 1 and 5000.');
        }
        $summary = ['processed' => 0, 'sent' => 0, 'skipped_muted' => 0, 'skipped_quiet_hours' => 0,
            'pruned_subscriptions' => 0, 'retried' => 0, 'failed' => 0, 'expired' => 0, 'stale_results' => 0];
        if (!$this->config->webPushEnabled()) {
            return $summary;
        }
        // Validate installation keys/transport before mutating queue state.
        $this->transport ??= new LibraryPushTransport($this->config);
        $now = ($this->clock)();
        $summary['pruned_jobs'] = 0;
        if ($this->lastPruneAt === null || $now->getTimestamp() - $this->lastPruneAt->getTimestamp() >= 60) {
            $summary['pruned_jobs'] = $this->queue->prune($now);
            $this->lastPruneAt = $now;
        }
        $materialized = $this->queue->materialize($limit, $now,
            fn (PushNotification $notification): ?string => $this->eligibility->suppression($notification, $now));
        foreach ($materialized as $name => $count) {
            $summary[$name] = ($summary[$name] ?? 0) + $count;
        }
        for ($index = 0; $index < $limit; $index++) {
            $claim = $this->queue->claim(($this->clock)());
            if ($claim === null) {
                break;
            }
            $this->deliver($claim, $summary);
        }
        return $summary + ($includeHealth ? $this->queue->health(($this->clock)())
            : ['has_due_work' => (int) $this->queue->hasDueWork(($this->clock)())]);
    }

    /** @param array<string,int> $summary */
    private function deliver(PushClaim $claim, array &$summary): void
    {
        $now = ($this->clock)();
        $ttl = PushDeliveryPolicy::remainingSeconds($claim->expiresAt, $now);
        $reason = $ttl <= 0 ? 'expired' : $this->eligibility->suppression($claim->notification, $now);
        if ($reason === null && ($claim->subscription === null
            || $claim->subscription['user_id'] !== $claim->notification->userId
            || !hash_equals($claim->fingerprint, PushDeliveryQueue::fingerprint($claim->subscription)))) {
            $reason = 'revoked';
        }
        if ($reason !== null) {
            if ($this->queue->finish($claim, $reason === 'expired' ? 'expired' : 'suppressed', $reason, $now) !== null) {
                $key = 'skipped_' . $reason;
                $summary[$key] = ($summary[$key] ?? 0) + 1;
            }
            return;
        }
        if ($claim->attempts > PushDeliveryPolicy::MAX_ATTEMPTS) {
            $this->queue->finish($claim, 'failed', 'attempt_limit', $now);
            $summary['failed']++;
            return;
        }

        $rendered = (new PrivacyNotificationService($this->pdo))->renderText($claim->notification->kind, $claim->notification->context);
        $payload = json_encode(['title' => $rendered['title'], 'body' => $rendered['message'],
            'link' => $rendered['link'], 'app_name' => (new ApplicationNameService($this->pdo, $this->config))->effective()],
            JSON_THROW_ON_ERROR);
        try {
            $result = $this->transport?->send($claim->subscription, $payload, $ttl, PushDeliveryPolicy::urgency($claim->notification->kind))
                ?? new PushSendResult('retry');
        } catch (Throwable) {
            $result = new PushSendResult('retry');
        }
        $now = ($this->clock)();
        $state = $result->outcome;
        $outcome = $state === 'sent' ? 'accepted' : ($state === 'expired' ? 'subscription_expired' : 'http_error');
        $retryAt = null;
        if ($state === 'retry') {
            $remaining = PushDeliveryPolicy::remainingSeconds($claim->expiresAt, $now);
            $delay = max(PushDeliveryPolicy::retryDelay($claim->attempts), $result->retryAfterSeconds ?? 0);
            if ($remaining <= 0 || $delay >= $remaining) {
                $state = 'expired';
                $outcome = 'deadline';
            } elseif ($claim->attempts >= PushDeliveryPolicy::MAX_ATTEMPTS) {
                $state = 'failed';
                $outcome = 'attempt_limit';
            } else {
                $state = 'pending';
                $retryAt = $now->add(new DateInterval('PT' . $delay . 'S'));
            }
        }
        $pruned = $this->queue->finish($claim, $state, $outcome, $now, $result->httpStatus, $retryAt,
            $result->outcome === 'expired' && in_array($result->httpStatus, [404, 410], true));
        if ($pruned === null) {
            $summary['stale_results']++;
            return;
        }
        $summary['pruned_subscriptions'] += $pruned;
        $key = ['sent' => 'sent', 'pending' => 'retried', 'failed' => 'failed', 'expired' => 'expired'][$state] ?? 'failed';
        $summary[$key]++;
    }
}
