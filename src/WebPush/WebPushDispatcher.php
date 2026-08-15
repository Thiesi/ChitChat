<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Config;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Periodic best-effort sweep over undelivered account_notifications rows.
 * Intended to run frequently via an operator-scheduled command (bin/dispatch-web-push),
 * not as a request-time side effect. See docs/architecture/0006-web-push.md.
 */
final class WebPushDispatcher
{
    /** @var list<string> notification kinds a user can mute via NotificationPreferenceService */
    private const MUTABLE_KINDS = ['mentioned'];

    private readonly PrivacyNotificationService $notifications;
    private readonly NotificationPreferenceService $preferences;
    private ?WebPush $webPush = null;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->notifications = new PrivacyNotificationService($pdo);
        $this->preferences = new NotificationPreferenceService($pdo);
    }

    /**
     * Constructed lazily so an unconfigured (disabled) installation never
     * runs VAPID key validation against empty configuration values — only
     * reached once dispatch() has already confirmed webPushEnabled().
     */
    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => $this->config->webPushVapidSubject,
                'publicKey' => $this->config->webPushVapidPublicKey,
                'privateKey' => $this->config->webPushVapidPrivateKey,
            ],
        ]);
    }

    /**
     * @return array{
     *   processed:int,
     *   sent:int,
     *   skipped_muted:int,
     *   skipped_quiet_hours:int,
     *   pruned_subscriptions:int
     * }
     */
    public function dispatch(int $limit = 500): array
    {
        $summary = [
            'processed' => 0,
            'sent' => 0,
            'skipped_muted' => 0,
            'skipped_quiet_hours' => 0,
            'pruned_subscriptions' => 0,
        ];
        if (!$this->config->webPushEnabled()) {
            return $summary;
        }
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('limit must be between 1 and 5000.');
        }

        foreach ($this->pendingNotifications($limit) as $notification) {
            $summary['processed']++;
            $this->dispatchOne($notification, $summary);
        }

        return $summary;
    }

    /** @return list<array{id:int, user_id:int, kind:string, context:array<string, mixed>}> */
    private function pendingNotifications(int $limit): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, user_id, kind, context_json::text AS context
FROM account_notifications
WHERE push_dispatched_at IS NULL
ORDER BY id
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare pending push notification lookup.');
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'kind' => (string) $row['kind'],
                'context' => $this->notifications->decodeContext((string) $row['context']),
            ];
        }

        return $result;
    }

    /**
     * @param array{id:int, user_id:int, kind:string, context:array<string, mixed>} $notification
     * @param array{processed:int, sent:int, skipped_muted:int, skipped_quiet_hours:int, pruned_subscriptions:int} $summary
     */
    private function dispatchOne(array $notification, array &$summary): void
    {
        $preferences = $this->preferences->get($notification['user_id']);

        if ($preferences['quiet_hours'] !== null && $this->inQuietHours($preferences['quiet_hours'])) {
            $summary['skipped_quiet_hours']++;
            $this->markDispatched($notification['id']);
            return;
        }
        if (in_array($notification['kind'], self::MUTABLE_KINDS, true) && !$preferences['mentioned_push_enabled']) {
            $summary['skipped_muted']++;
            $this->markDispatched($notification['id']);
            return;
        }

        $rendered = $this->notifications->renderText($notification['kind'], $notification['context']);
        $payload = json_encode(
            ['title' => $rendered['title'], 'body' => $rendered['message'], 'link' => $rendered['link']],
            JSON_THROW_ON_ERROR,
        );

        foreach ($this->subscriptionsFor($notification['user_id']) as $subscription) {
            $this->sendOne($subscription, $payload, $summary);
        }

        $this->markDispatched($notification['id']);
    }

    /** @return list<array{id:int, endpoint:string, p256dh_key:string, auth_key:string}> */
    private function subscriptionsFor(int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, endpoint, p256dh_key, auth_key
FROM push_subscriptions
WHERE user_id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push subscription lookup.');
        }
        $statement->execute(['user_id' => $userId]);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'endpoint' => (string) $row['endpoint'],
                'p256dh_key' => (string) $row['p256dh_key'],
                'auth_key' => (string) $row['auth_key'],
            ];
        }

        return $result;
    }

    /**
     * @param array{id:int, endpoint:string, p256dh_key:string, auth_key:string} $subscriptionRow
     * @param array{processed:int, sent:int, skipped_muted:int, skipped_quiet_hours:int, pruned_subscriptions:int} $summary
     */
    private function sendOne(array $subscriptionRow, string $payload, array &$summary): void
    {
        $subscription = Subscription::create([
            'endpoint' => $subscriptionRow['endpoint'],
            'keys' => [
                'p256dh' => $subscriptionRow['p256dh_key'],
                'auth' => $subscriptionRow['auth_key'],
            ],
            'contentEncoding' => 'aes128gcm',
        ]);

        try {
            $report = $this->webPush()->sendOneNotification($subscription, $payload);
        } catch (Throwable) {
            return;
        }

        if ($report->isSuccess()) {
            $summary['sent']++;
            return;
        }
        if ($report->isSubscriptionExpired()) {
            $this->pruneSubscription($subscriptionRow['id']);
            $summary['pruned_subscriptions']++;
        }
    }

    private function pruneSubscription(int $subscriptionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare expired push subscription removal.');
        }
        $statement->execute(['id' => $subscriptionId]);
    }

    private function markDispatched(int $notificationId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE account_notifications SET push_dispatched_at = NOW() WHERE id = :id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push dispatch marker.');
        }
        $statement->execute(['id' => $notificationId]);
    }

    /** @param array{start:int, end:int, timezone:string} $quietHours */
    private function inQuietHours(array $quietHours): bool
    {
        if ($quietHours['start'] === $quietHours['end']) {
            return false;
        }
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($quietHours['timezone']));
        } catch (Throwable) {
            return false;
        }
        $hour = (int) $now->format('G');

        if ($quietHours['start'] < $quietHours['end']) {
            return $hour >= $quietHours['start'] && $hour < $quietHours['end'];
        }

        return $hour >= $quietHours['start'] || $hour < $quietHours['end'];
    }
}
