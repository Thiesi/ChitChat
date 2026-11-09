<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\IgnoreService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\ScriptedPushTransport;
use ChitChat\WebPush\NotificationPreferenceService;
use ChitChat\WebPush\PushDeliveryQueue;
use ChitChat\WebPush\PushNotification;
use ChitChat\WebPush\PushSendResult;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\WebPush\WebPushDispatcher;
use DateTimeImmutable;
use Minishlink\WebPush\VAPID;
use PDO;

final class PushDeliveryTest extends DatabaseTestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable();
    }

    public function testReadExpiredAndPreviouslyProcessedRowsNeverCreateDeliveries(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $read = $this->notice($member);
        $expired = $this->notice($member);
        $processed = $this->notice($member);
        $this->pdo->exec("UPDATE account_notifications SET read_at = NOW() WHERE id = $read");
        $this->pdo->exec("UPDATE account_notifications SET created_at = NOW() - INTERVAL '7 hours' WHERE id = $expired");
        $this->pdo->exec("UPDATE account_notifications SET push_dispatched_at = NOW() WHERE id = $processed");
        $transport = new ScriptedPushTransport();
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(2, $summary['processed']);
        self::assertSame(1, $summary['skipped_read']);
        self::assertSame(1, $summary['skipped_expired']);
        self::assertCount(0, $transport->calls);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_deliveries')->fetchColumn());
    }

    public function testPartialFailureRetriesOnlyTheFailedDeviceWithRemainingLifetime(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $this->subscribe($member, 'two');
        $id = $this->notice($member);
        $this->subscribe($member, 'registered-after-event');
        $transport = new ScriptedPushTransport([new PushSendResult('sent', 201),
            new PushSendResult('retry', 503, 120), new PushSendResult('sent', 201)]);
        $this->now = new DateTimeImmutable();
        $first = $this->worker($transport)->dispatch();
        self::assertSame(1, $first['sent']);
        self::assertSame(1, $first['retried']);
        self::assertNull($this->marker($id));
        self::assertCount(2, $transport->calls);
        $this->subscribe($member, 'new-device');
        $this->now = $this->now->modify('+121 seconds');
        $second = $this->worker($transport)->dispatch();
        self::assertSame(1, $second['sent']);
        self::assertCount(3, $transport->calls);
        self::assertSame($transport->calls[1]['subscription']['id'], $transport->calls[2]['subscription']['id']);
        self::assertLessThan($transport->calls[1]['ttl'], $transport->calls[2]['ttl']);
        self::assertSame('normal', $transport->calls[0]['urgency']);
        self::assertNotNull($this->marker($id));
        self::assertSame(0, $second['pending_deliveries']);
        $payload = json_decode($transport->calls[0]['payload'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['title', 'body', 'link', 'app_name', 'kind', 'notification_id', 'event_id', 'binding'], array_keys($payload));
    }

    public function testPermanentProviderFailuresDoNotRemoveValidDevicesAndRotationIsSafe(): void
    {
        $member = $this->member('Member');
        foreach (['unauthorized', 'forbidden', 'missing', 'gone'] as $key) {
            $this->subscribe($member, $key);
        }
        $id = $this->notice($member);
        $this->now = new DateTimeImmutable();
        $transport = new ScriptedPushTransport([new PushSendResult('failed', 401), new PushSendResult('failed', 403),
            new PushSendResult('expired', 404), new PushSendResult('expired', 410)]);
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(2, $summary['pruned_subscriptions']);
        self::assertSame(2, $summary['failed']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        self::assertNotNull($this->marker($id));

        $this->pdo->exec('DELETE FROM push_subscriptions');
        $device = $this->subscribe($member, 'rotating');
        $this->notice($member);
        $other = Database::connect($this->config);
        $transport = new ScriptedPushTransport([new PushSendResult('expired', 410)],
            static function () use ($other, $device): void {
                $other->exec("UPDATE push_subscriptions SET auth_key = 'new-key' WHERE id = $device");
            });
        $this->now = new DateTimeImmutable();
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(0, $summary['pruned_subscriptions'], 'An expired old registration must not remove new credentials.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
    }

    public function testConcurrentClaimsSkipLocksAndExpiredTokensCannotFinishOrPrune(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $firstId = $this->notice($member);
        $secondId = $this->notice($member);
        $this->now = new DateTimeImmutable();
        $queue = new PushDeliveryQueue($this->pdo);
        $queue->materialize(10, $this->now, static fn (PushNotification $notification): ?string => null);
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $otherQueue = new PushDeliveryQueue($other);
        $this->pdo->beginTransaction();
        try {
            $firstJob = (int) $this->pdo->query('SELECT id FROM push_dispatch_jobs ORDER BY id LIMIT 1 FOR UPDATE')->fetchColumn();
            $claim = $otherQueue->claim($this->now);
            self::assertNotNull($claim);
            self::assertNotSame($firstJob, $claim->jobId);
            self::assertSame($secondId, $claim->notification->id);
            self::assertSame(0, $otherQueue->finish($claim, 'sent', 'accepted', $this->now));
        } finally {
            $this->pdo->rollBack();
        }
        $old = $queue->claim($this->now);
        self::assertNotNull($old);
        self::assertSame($firstId, $old->notification->id);
        self::assertNull($otherQueue->claim($this->now), 'An unexpired event lease excludes another worker.');
        $this->now = $this->now->modify('+61 seconds');
        $replacement = $otherQueue->claim($this->now);
        self::assertNotNull($replacement);
        self::assertSame($old->deliveryId, $replacement->deliveryId);
        self::assertNotSame($old->token, $replacement->token);
        self::assertNull($queue->finish($old, 'expired', 'subscription_expired', $this->now, 410, null, true));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        self::assertSame(0, $otherQueue->finish($replacement, 'sent', 'accepted', $this->now));
        self::assertNotNull($this->marker($firstId));
    }

    public function testReadQuietHoursAndPreferencesSuppressRetriesWithoutMorningReplay(): void
    {
        $sender = $this->member('Sender');
        $recipients = [$this->member('Read'), $this->member('Muted'), $this->member('Quiet')];
        foreach ($recipients as $recipient) {
            $this->subscribe($recipient, $recipient->username);
            (new DirectMessageService($this->pdo))->send($sender, $recipient->id, '@' . $recipient->username);
        }
        $transport = new ScriptedPushTransport(array_fill(0, 3, new PushSendResult('retry', 503, 120)));
        $this->now = new DateTimeImmutable();
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(3, $summary['retried']);
        self::assertSame('high', $transport->calls[0]['urgency']);
        self::assertLessThanOrEqual(1800, $transport->calls[0]['ttl']);
        self::assertGreaterThan(1700, $transport->calls[0]['ttl']);
        $this->pdo->exec('UPDATE account_notifications SET read_at = NOW() WHERE user_id = ' . $recipients[0]->id);
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setMentionedPushEnabled($recipients[1]->id, false);
        $this->now = $this->now->modify('+121 seconds');
        $hour = (int) $this->now->format('G');
        $preferences->setQuietHours($recipients[2]->id, $hour, ($hour + 1) % 24, $this->now->getTimezone()->getName());
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(1, $summary['skipped_read']);
        self::assertSame(1, $summary['skipped_muted']);
        self::assertSame(1, $summary['skipped_quiet_hours']);
        self::assertCount(3, $transport->calls);
        $preferences->setMentionedPushEnabled($recipients[1]->id, true);
        $preferences->setQuietHours($recipients[2]->id, null, null, null);
        $this->now = $this->now->modify('+2 hours');
        $this->worker($transport)->dispatch();
        self::assertCount(3, $transport->calls);
    }

    public function testDmReadBlockDeletionBanAndKeyChangesAreRecheckedBeforeRetry(): void
    {
        $pairs = [];
        $transport = new ScriptedPushTransport(array_fill(0, 5, new PushSendResult('retry', 503, 120)));
        for ($i = 0; $i < 5; $i++) {
            $sender = $this->member('Sender' . $i);
            $recipient = $this->member('Member' . $i);
            $device = $this->subscribe($recipient, (string) $i);
            $message = (new DirectMessageService($this->pdo))->send($sender, $recipient->id, '@' . $recipient->username);
            $pairs[] = [$sender, $recipient, $message, $device];
        }
        $this->now = new DateTimeImmutable();
        self::assertSame(5, $this->worker($transport)->dispatch()['retried']);
        (new DirectMessageService($this->pdo))->markRead($pairs[0][1], $pairs[0][0]->id);
        (new DirectMessageBlockService($this->pdo))->block($pairs[1][1], $pairs[1][0]->id);
        $this->pdo->exec('UPDATE direct_messages SET deleted_at = NOW() WHERE id = ' . $pairs[2][2]['id']);
        $this->pdo->exec("INSERT INTO user_bans (user_id, created_by, reason) VALUES ({$pairs[3][1]->id}, {$pairs[0][0]->id}, 'Fixture')");
        $this->pdo->exec("UPDATE push_subscriptions SET auth_key = 'rotated' WHERE id = {$pairs[4][3]}");
        $this->now = $this->now->modify('+121 seconds');
        $summary = $this->worker($transport)->dispatch();
        self::assertSame(1, $summary['skipped_read']);
        self::assertSame(3, $summary['skipped_forbidden']);
        self::assertSame(1, $summary['skipped_revoked']);
        self::assertCount(5, $transport->calls);
    }

    public function testRoomIgnoreAndPrivateHistoryLossSuppressPendingMentions(): void
    {
        $sender = $this->member('Sender');
        $ignored = $this->member('Ignored');
        $removed = $this->member('Removed');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($sender, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        foreach ([$ignored, $removed] as $recipient) {
            $rooms->invite($sender, $room->id, $recipient->id, '127.0.0.1');
            $rooms->join($recipient, $room->id, '127.0.0.1');
            $this->subscribe($recipient, $recipient->username);
        }
        (new MessageService($this->pdo))->send($sender, $room->id, '@Ignored @Removed');
        $transport = new ScriptedPushTransport(array_fill(0, 2, new PushSendResult('retry', 503, 120)));
        $this->now = new DateTimeImmutable();
        self::assertSame(2, $this->worker($transport)->dispatch()['retried']);
        (new IgnoreService($this->pdo))->setIgnored($ignored, $sender->id, true);
        $rooms->leave($removed, $room->id, '127.0.0.1');
        $this->now = $this->now->modify('+121 seconds');
        self::assertSame(2, $this->worker($transport)->dispatch()['skipped_forbidden']);
        self::assertCount(2, $transport->calls);
    }

    public function testAttemptLimitRetryAfterDeadlineAndQueueHistoryStayBounded(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $id = $this->notice($member);
        $this->now = new DateTimeImmutable();
        $transport = new ScriptedPushTransport(array_fill(0, 5, new PushSendResult('retry', 500)));
        for ($i = 0; $i < 6; $i++) {
            $this->worker($transport)->dispatch();
            $this->now = $this->now->modify('+3 minutes');
        }
        self::assertCount(5, $transport->calls);
        self::assertNotNull($this->marker($id));
        self::assertSame(5, (int) $this->pdo->query('SELECT attempts FROM push_deliveries')->fetchColumn());
        $expiredId = $this->notice($member);
        $transport = new ScriptedPushTransport([new PushSendResult('retry', 429, PHP_INT_MAX)]);
        self::assertSame(1, $this->worker($transport)->dispatch()['expired']);
        self::assertNotNull($this->marker($expiredId));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        $this->pdo->exec("UPDATE push_dispatch_jobs SET finished_at = NOW() - INTERVAL '8 days'");
        self::assertSame(2, $this->worker($transport)->dispatch()['pruned_jobs']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_deliveries')->fetchColumn());
        self::assertCount(1, $transport->calls, 'Pruned history never replays terminal notifications.');
    }

    public function testTransportRunsOutsideTransactionsAndLaterDevicesDoNotReceiveOldEvents(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $id = $this->notice($member);
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $transport = new ScriptedPushTransport([], function () use ($other, $id): void {
            self::assertFalse($this->pdo->inTransaction());
            // This update would block if the claim still held the source row lock.
            $other->exec("UPDATE account_notifications SET read_at = NOW() WHERE id = $id");
        });
        $this->now = new DateTimeImmutable();
        self::assertSame(1, $this->worker($transport)->dispatch()['sent']);
        $this->worker($transport)->dispatch();
        self::assertCount(1, $transport->calls);
        $this->pdo->exec('DELETE FROM push_subscriptions');
        $empty = $this->notice($member);
        $this->worker($transport)->dispatch();
        self::assertNotNull($this->marker($empty));
        $this->subscribe($member, 'later');
        $this->worker($transport)->dispatch();
        self::assertCount(1, $transport->calls);
    }


    public function testMalformedInstallationKeysFailBeforeMaterialization(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $id = $this->notice($member);
        $config = $this->configWith(['webPushVapidPublicKey' => 'bad', 'webPushVapidPrivateKey' => 'bad',
            'webPushVapidSubject' => 'mailto:admin@example.test']);
        try {
            (new WebPushDispatcher($this->pdo, $config))->dispatch();
            self::fail('Malformed VAPID keys must fail before work is claimed.');
        } catch (\ErrorException $exception) {
            self::assertStringContainsString('VAPID', $exception->getMessage());
        }
        self::assertNull($this->marker($id));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_dispatch_jobs')->fetchColumn());
    }

    public function testRepeatedWorkerCrashesCannotExceedTheAttemptBudget(): void
    {
        $member = $this->member('Member');
        $this->subscribe($member, 'one');
        $id = $this->notice($member);
        $this->now = new DateTimeImmutable();
        $queue = new PushDeliveryQueue($this->pdo);
        $queue->materialize(1, $this->now, static fn (PushNotification $notification): ?string => null);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $claim = $queue->claim($this->now);
            self::assertNotNull($claim);
            self::assertSame($attempt, $claim->attempts);
            $this->now = $this->now->modify('+61 seconds');
        }
        $transport = new ScriptedPushTransport();
        self::assertSame(1, $this->worker($transport)->dispatch()['failed']);
        self::assertCount(0, $transport->calls);
        self::assertSame(5, (int) $this->pdo->query('SELECT attempts FROM push_deliveries')->fetchColumn());
        self::assertNotNull($this->marker($id));
    }

    public function testRoomAgeAndSenderPostingRestrictionsAreRecheckedAfterQueuing(): void
    {
        $root = $this->member('Root');
        $sender = $this->member('Sender');
        $recipient = $this->member('Recipient');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'public', 'Public', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($sender, $room->id, '127.0.0.1');
        $rooms->join($recipient, $room->id, '127.0.0.1');
        $this->subscribe($recipient, 'one');
        (new MessageService($this->pdo))->send($sender, $room->id, '@Recipient');
        (new DirectMessageService($this->pdo))->send($sender, $recipient->id, '@Recipient');
        $this->now = new DateTimeImmutable();
        $transport = new ScriptedPushTransport(array_fill(0, 2, new PushSendResult('retry', 503, 120)));
        self::assertSame(2, $this->worker($transport)->dispatch()['retried']);
        $this->pdo->exec('UPDATE rooms SET minimum_age = 18 WHERE id = ' . $room->id);
        (new \ChitChat\Moderation\MuteService($this->pdo))->mute($root, $sender->id, null, null, 'Fixture', false, '127.0.0.1');
        $this->now = $this->now->modify('+121 seconds');
        self::assertSame(2, $this->worker($transport)->dispatch()['skipped_forbidden']);
        self::assertCount(2, $transport->calls);
    }

    private function member(string $name): AuthenticatedUser
    {
        return (new AuthService($this->pdo, $this->config))->register($name, 'a very secure password', '127.0.0.1');
    }

    private function subscribe(AuthenticatedUser $member, string $suffix): int
    {
        (PushFixtures::subscriptions($this->pdo))->subscribe($member, 'https://push.example/' . $suffix, PushFixtures::key(), PushFixtures::auth(), null, str_repeat('a', 64));
        return (int) $this->pdo->query('SELECT MAX(id) FROM push_subscriptions')->fetchColumn();
    }

    private function notice(AuthenticatedUser $member): int
    {
        $statement = $this->pdo->prepare("INSERT INTO account_notifications (user_id, kind) VALUES (:user, 'admin_password_reset') RETURNING id");
        $statement->execute(['user' => $member->id]);
        return (int) $statement->fetchColumn();
    }

    private function marker(int $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT push_dispatched_at FROM account_notifications WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetchColumn() ?: null;
    }

    private function worker(ScriptedPushTransport $transport): WebPushDispatcher
    {
        static $keys = null;
        $keys ??= VAPID::createVapidKeys();
        return new WebPushDispatcher($this->pdo, $this->configWith(['webPushVapidPublicKey' => $keys['publicKey'],
            'webPushVapidPrivateKey' => $keys['privateKey'], 'webPushVapidSubject' => 'mailto:admin@example.test']),
            $transport, fn (): DateTimeImmutable => $this->now);
    }
}
