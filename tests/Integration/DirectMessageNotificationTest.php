<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\Tests\Support\ScriptedPushTransport;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Upload\IncomingFile;
use ChitChat\WebPush\NotificationPreferenceService;
use ChitChat\WebPush\PushDeliveryQueue;
use ChitChat\WebPush\PushEligibility;
use ChitChat\WebPush\PushNotification;
use ChitChat\WebPush\PushSendResult;
use ChitChat\WebPush\WebPushDispatcher;
use DateTimeImmutable;
use DateTimeZone;
use Minishlink\WebPush\VAPID;
use PDOException;

final class DirectMessageNotificationTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private AuthenticatedUser $root;
    private AuthenticatedUser $sender;
    private AuthenticatedUser $recipient;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $this->root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $this->sender = $auth->register('Alice', 'a very secure password', '127.0.0.2');
        $this->recipient = $auth->register('Bob', 'a very secure password', '127.0.0.3');
        $this->now = new DateTimeImmutable();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();
        parent::tearDown();
    }

    public function testDefaultOffOptInTextAndAttachmentsContainOnlyIdsAndNeverBackfill(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        self::assertFalse($preferences->get($this->recipient->id)['direct_message_push_enabled']);
        $preferences->setDirectMessagePushEnabled($this->sender->id, true);
        $messages = new DirectMessageService($this->pdo);
        $messages->send($this->sender, $this->recipient->id, 'old private body');
        self::assertSame([], $this->directRows());
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        self::assertSame([], $this->directRows(), 'Enabling never backfills.');
        $text = $messages->send($this->sender, $this->recipient->id, 'new private body');
        $file = (new DirectMessageAttachmentService($this->pdo,
            $this->configWith(['attachmentStoragePath' => $this->temporaryDirectory()])))->upload(
                $this->sender, $this->recipient->id,
                IncomingFile::forTesting('secret-filename.txt', $this->temporaryFile('secret file bytes')),
                'private caption', '127.0.0.2');
        $rows = $this->directRows();
        self::assertCount(2, $rows);
        foreach ($rows as $index => $row) {
            self::assertSame($this->recipient->id, (int) $row['user_id']);
            self::assertEquals(['message_kind' => 'direct', 'message_id' => [$text['id'], $file['id']][$index],
                'sender_user_id' => $this->sender->id], json_decode($row['context_json'], true, 16, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('private', $row['context_json']);
            self::assertStringNotContainsString('filename', $row['context_json']);
        }
        (new DirectMessageMutationService($this->pdo))->edit($this->sender, $text['id'], 'edited private body', '127.0.0.2');
        self::assertCount(2, $this->directRows(), 'Edits create no new alerts.');
    }

    public function testNotificationFailureRollsBackBothMessageProducersAndStoredFiles(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $storage = $this->temporaryDirectory();
        $this->pdo->exec(<<<'SQL'
CREATE FUNCTION cc_test_reject_dm_alert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind = 'direct_message_received' THEN RAISE EXCEPTION 'expected notification rollback'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cc_test_reject_dm_alert BEFORE INSERT ON account_notifications FOR EACH ROW EXECUTE FUNCTION cc_test_reject_dm_alert();
SQL);
        try {
            foreach ([false, true] as $attachment) {
                try {
                    if ($attachment) {
                        (new DirectMessageAttachmentService($this->pdo,
                            $this->configWith(['attachmentStoragePath' => $storage])))->upload($this->sender, $this->recipient->id,
                            IncomingFile::forTesting('secret.txt', $this->temporaryFile('secret bytes')), 'caption', '127.0.0.2');
                    } else {
                        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private text');
                    }
                    self::fail('Expected the insert transaction to roll back.');
                } catch (PDOException $exception) {
                    self::assertStringContainsString('expected notification rollback', $exception->getMessage());
                }
                self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM direct_messages')->fetchColumn());
                self::assertSame([], $this->directRows());
                self::assertSame(0, TestScratch::countFiles($storage));
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER cc_test_reject_dm_alert ON account_notifications; DROP FUNCTION cc_test_reject_dm_alert()');
        }
    }

    public function testEveryPreferenceCombinationProducesAtMostOneAlertAndKeepsBothSemanticRows(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $this->subscribe('one');
        foreach ([[false, false, null], [true, false, 'mentioned'], [false, true, 'direct_message_received'], [true, true, 'mentioned']] as [$mention, $direct, $expected]) {
            $preferences->setMentionedPushEnabled($this->recipient->id, $mention);
            $preferences->setDirectMessagePushEnabled($this->recipient->id, $direct);
            $message = (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private @Bob message');
            $transport = new ScriptedPushTransport();
            $this->worker($transport)->dispatch();
            self::assertCount($expected === null ? 0 : 1, $transport->calls);
            if ($expected !== null) {
                $payload = json_decode($transport->calls[0]['payload'], true, 16, JSON_THROW_ON_ERROR);
                self::assertSame($expected, $payload['kind']);
                self::assertStringContainsString('with=' . $this->sender->id, $payload['link']);
                self::assertStringNotContainsString('private', $transport->calls[0]['payload']);
                self::assertSame('high', $transport->calls[0]['urgency']);
            }
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM account_notifications WHERE user_id = :user AND context_json->>'message_id' = :id");
            $statement->execute(['user' => $this->recipient->id, 'id' => (string) $message['id']]);
            self::assertSame($direct ? 2 : 1, (int) $statement->fetchColumn());
        }
    }

    public function testFallbackCanChangeBeforeAcceptanceButThenFreezesForRemainingDevices(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $this->subscribe('two');
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private @Bob');
        $transport = new ScriptedPushTransport([new PushSendResult('retry', 503, 120), new PushSendResult('sent', 201),
            new PushSendResult('sent', 201)]);
        $worker = $this->worker($transport);
        $worker->dispatch(1);
        $preferences->setMentionedPushEnabled($this->recipient->id, false);
        $worker->dispatch(1);
        $preferences->setMentionedPushEnabled($this->recipient->id, true);
        $this->now = $this->now->modify('+121 seconds');
        $worker->dispatch(1);
        self::assertCount(3, $transport->calls);
        $payloads = array_map(static fn (array $call): array => json_decode($call['payload'], true, 16, JSON_THROW_ON_ERROR), $transport->calls);
        self::assertSame(['mentioned', 'direct_message_received', 'direct_message_received'], array_column($payloads, 'kind'));
        self::assertCount(1, array_unique(array_column($payloads, 'event_id')));
        self::assertSame($transport->calls[0]['subscription']['id'], $transport->calls[2]['subscription']['id']);
        self::assertNotSame($transport->calls[1]['subscription']['id'], $transport->calls[2]['subscription']['id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_dispatch_jobs')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM push_dispatch_sources')->fetchColumn());
    }

    public function testAcceptedMentionNeverFallsBackWhenPreferenceChangesOrSelectedSourceIsPurged(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $this->subscribe('two');
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, '@Bob private');
        $transport = new ScriptedPushTransport();
        $worker = $this->worker($transport);
        $worker->dispatch(1);
        $this->pdo->exec("DELETE FROM account_notifications WHERE kind = 'mentioned'");
        self::assertSame(1, $worker->dispatch()['skipped_forbidden']);
        self::assertCount(1, $transport->calls);
        self::assertCount(1, $this->directRows());
    }

    public function testQuietHoursAndConversationReadsAreTerminalWithoutMorningReplay(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('G');
        $preferences->setQuietHours($this->recipient->id, $hour, ($hour + 1) % 24, 'UTC');
        $this->subscribe('one');
        $messages = new DirectMessageService($this->pdo);
        $messages->send($this->sender, $this->recipient->id, '@Bob private');
        $transport = new ScriptedPushTransport();
        $worker = $this->worker($transport);
        self::assertSame(2, $worker->dispatch()['skipped_quiet_hours']);
        $preferences->setQuietHours($this->recipient->id, null, null, null);
        $worker->dispatch();
        self::assertCount(0, $transport->calls);
        self::assertSame(1, $messages->markRead($this->recipient, $this->sender->id));
        self::assertNotNull($this->directRows()[0]['read_at']);
        $message = $messages->send($this->sender, $this->recipient->id, '@Bob private again');
        self::assertSame(1, $messages->markRead($this->recipient, $this->sender->id));
        $worker->dispatch();
        self::assertCount(0, $transport->calls, 'A read DM cannot later push via its unread mention row.');
    }

    public function testSenderDeletionUsesSafeFallbackWithoutSavingNamesInNotificationContext(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private');
        $service = new PrivacyNotificationService($this->pdo);
        $normal = $service->timeline($this->recipient)['notifications'][0];
        self::assertSame('Alice sent you a direct message.', $normal['message']);
        self::assertSame('/messages.php?with=' . $this->sender->id, $normal['link']);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->sender->id);
        $closed = $service->timeline($this->recipient)['notifications'][0];
        self::assertSame('Someone sent you a direct message.', $closed['message']);
        self::assertSame('/messages.php', $closed['link']);
    }

    public function testRecipientPostingMuteDoesNotStopReceivingButBlockAndSenderMuteDo(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $mutes = new MuteService($this->pdo);
        $mutes->mute($this->root, $this->recipient->id, null, null, 'Posting pause', false, '127.0.0.1');
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private');
        $transport = new ScriptedPushTransport();
        $worker = $this->worker($transport);
        $worker->dispatch();
        $direct = array_filter($transport->calls, static fn (array $call): bool => json_decode($call['payload'], true)['kind'] === 'direct_message_received');
        self::assertCount(1, $direct);

        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private later');
        (new DirectMessageBlockService($this->pdo))->block($this->recipient, $this->sender->id);
        $before = count($transport->calls);
        $worker->dispatch();
        self::assertCount($before, $transport->calls);
        (new DirectMessageBlockService($this->pdo))->unblock($this->recipient, $this->sender->id);
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private again');
        $mutes->mute($this->root, $this->sender->id, null, null, 'Sending pause', false, '127.0.0.1');
        $worker->dispatch();
        self::assertCount($before, $transport->calls);
    }

    public function testAtomicPreferenceUpdatePreservesNewFieldForLegacyRequests(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        try {
            $preferences->update($this->recipient->id, false, false, false, 22, 7, 'not/a-zone');
            self::fail('Invalid settings were accepted.');
        } catch (ApiException) {
            $current = $preferences->get($this->recipient->id);
            self::assertTrue($current['mentioned_push_enabled']);
            self::assertTrue($current['pinged_push_enabled']);
            self::assertTrue($current['direct_message_push_enabled']);
        }
        $preferences->update($this->recipient->id, false, null, null, null, null, null);
        self::assertTrue($preferences->get($this->recipient->id)['direct_message_push_enabled']);
    }

    public function testBusyEventGroupDoesNotWaitWithASiblingSourceLockOrCreateDuplicateJobs(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $message = (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, '@Bob private');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '200ms'");
        $queue = new PushDeliveryQueue($other);
        $eligibility = new PushEligibility($other);
        $now = $queue->now();
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:event, 0))');
        $lock->execute(['event' => 'push:' . $this->recipient->id . ':direct:' . $message['id']]);
        try {
            self::assertSame(0, $queue->materialize(2, $now, static fn (PushNotification $n): ?string => $eligibility->suppression($n, $now))['processed']);
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame(2, $queue->materialize(2, $now, static fn (PushNotification $n): ?string => $eligibility->suppression($n, $now))['processed']);
        self::assertSame(0, (new PushDeliveryQueue($this->pdo))->materialize(2, $now, static fn (): ?string => null)['processed']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_dispatch_jobs')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM push_dispatch_sources')->fetchColumn());
    }

    private function subscribe(string $suffix): void
    {
        PushFixtures::subscriptions($this->pdo)->subscribe($this->recipient, 'https://push.example/' . $suffix,
            PushFixtures::key(), PushFixtures::auth(), null, str_repeat('a', 64));
    }

    /** @return list<array<string,mixed>> */
    private function directRows(): array
    {
        return $this->pdo->query("SELECT * FROM account_notifications WHERE kind = 'direct_message_received' ORDER BY id")->fetchAll();
    }

    private function worker(ScriptedPushTransport $transport): WebPushDispatcher
    {
        static $keys = null;
        $keys ??= VAPID::createVapidKeys();
        $this->now = new DateTimeImmutable();
        return new WebPushDispatcher($this->pdo, $this->configWith(['webPushVapidPublicKey' => $keys['publicKey'],
            'webPushVapidPrivateKey' => $keys['privateKey'], 'webPushVapidSubject' => 'mailto:admin@example.test']),
            $transport, fn (): DateTimeImmutable => $this->now);
    }

    public function testFrozenAcceptedMentionDoesNotSwitchKindsForOtherDevices(): void
    {
        $preferences = new NotificationPreferenceService($this->pdo);
        $preferences->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $this->subscribe('two');
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, '@Bob private');
        $transport = new ScriptedPushTransport();
        $worker = $this->worker($transport);
        $worker->dispatch(1);
        $preferences->setMentionedPushEnabled($this->recipient->id, false);
        self::assertSame(1, $worker->dispatch()['skipped_muted']);
        self::assertCount(1, $transport->calls);
    }

    public function testPurgedUnacceptedMentionFallsBackWithTheSameEventId(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, '@Bob private');
        $transport = new ScriptedPushTransport([new PushSendResult('retry', 503, 120), new PushSendResult('sent', 201)]);
        $worker = $this->worker($transport);
        $worker->dispatch();
        $this->pdo->exec("DELETE FROM account_notifications WHERE kind = 'mentioned'");
        $this->now = $this->now->modify('+121 seconds');
        $worker->dispatch();
        self::assertCount(2, $transport->calls);
        $first = json_decode($transport->calls[0]['payload'], true, 16, JSON_THROW_ON_ERROR);
        $second = json_decode($transport->calls[1]['payload'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('mentioned', $first['kind']);
        self::assertSame('direct_message_received', $second['kind']);
        self::assertSame($first['event_id'], $second['event_id']);
    }

    /** @return array<string,array{string}> */
    public static function ineligibleRetryCases(): array
    {
        return ['read' => ['read'], 'reverse block' => ['block'], 'deleted' => ['deleted'],
            'sender banned' => ['sender_banned'], 'recipient banned' => ['recipient_banned']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibleRetryCases')]
    public function testRetryRechecksTheMessageParticipantsAndReadState(string $change): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $this->subscribe('one');
        $message = (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'private');
        $transport = new ScriptedPushTransport([new PushSendResult('retry', 503, 120)]);
        $worker = $this->worker($transport);
        $worker->dispatch();
        if ($change === 'read') {
            (new DirectMessageService($this->pdo))->markRead($this->recipient, $this->sender->id);
        } elseif ($change === 'block') {
            (new DirectMessageBlockService($this->pdo))->block($this->sender, $this->recipient->id);
        } elseif ($change === 'deleted') {
            (new DirectMessageMutationService($this->pdo))->deleteOwn($this->sender, $message['id'], '127.0.0.2');
        } else {
            (new \ChitChat\Moderation\ModerationService($this->pdo))->ban($this->root,
                $change === 'sender_banned' ? $this->sender->id : $this->recipient->id, 'Review', null, '127.0.0.1');
        }
        $this->now = $this->now->modify('+121 seconds');
        $worker->dispatch();
        self::assertCount(1, $transport->calls);
        self::assertSame('suppressed', $this->pdo->query('SELECT state FROM push_deliveries')->fetchColumn());
    }

    public function testReadAcknowledgementDoesNotSweepAMessageCommittedWhileItWaits(): void
    {
        (new NotificationPreferenceService($this->pdo))->setDirectMessagePushEnabled($this->recipient->id, true);
        $old = (new DirectMessageService($this->pdo))->send($this->sender, $this->recipient->id, 'old private');
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT id FROM direct_messages WHERE id = ' . $old['id'] . ' FOR UPDATE');
        $code = <<<'PHP'
$pdo->exec("SET application_name = 'cc_dm_read_coverage'");
$actor = (new \ChitChat\Auth\UserRepository($pdo))->findAuthenticatedById((int) $argv[2]);
if ($actor === null) { throw new RuntimeException('Missing fixture actor.'); }
echo 'finished:' . (new \ChitChat\DirectMessage\DirectMessageService($pdo))->markRead($actor, (int) $argv[3]);
PHP;
        [$process, $log] = $this->startChild($code, [(string) $this->recipient->id, (string) $this->sender->id]);
        try {
            $this->waitForChildLock($process, 'cc_dm_read_coverage', $log);
            $writer = Database::connect($this->config);
            $new = (new DirectMessageService($writer))->send($this->sender, $this->recipient->id, 'new private');
            $this->pdo->commit();
            $this->waitForChildOutput($process, $log, 'finished:1');
            $rows = $this->pdo->query('SELECT id, recipient_read_at FROM direct_messages ORDER BY id')->fetchAll();
            self::assertSame($old['id'], (int) $rows[0]['id']);
            self::assertNotNull($rows[0]['recipient_read_at']);
            self::assertSame($new['id'], (int) $rows[1]['id']);
            self::assertNull($rows[1]['recipient_read_at']);
            $alerts = $this->directRows();
            self::assertSame($rows[0]['recipient_read_at'], $alerts[0]['read_at']);
            self::assertNull($alerts[1]['read_at'], 'A concurrent new alert must remain unread.');
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
        }
    }

    /** @param list<string> $arguments @return array{resource,string} */
    private function startChild(string $code, array $arguments): array
    {
        $script = $this->temporaryFile("<?php\nrequire \$argv[1];\n"
            . "\$pdo = \\ChitChat\\Database::connect(\\ChitChat\\Config::fromEnvironment());\n" . $code);
        $log = $this->temporaryFile('');
        $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2) . '/vendor/autoload.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $log];
    }

    /** @param resource $process */
    private function waitForChildLock($process, string $name, string $log): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $this->pdo->query('SELECT pg_stat_clear_snapshot()');
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
            $statement->execute(['name' => $name]);
            if ((int) $statement->fetchColumn() > 0) return;
            usleep(10_000);
        } while (hrtime(true) < $deadline && proc_get_status($process)['running']);
        self::fail('Child never reached its expected database wait: ' . (string) file_get_contents($log));
    }

    /** @param resource $process */
    private function waitForChildOutput($process, string $log, string $expected): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            if (str_contains((string) file_get_contents($log), $expected)) return;
            usleep(10_000);
        } while (hrtime(true) < $deadline && proc_get_status($process)['running']);
        self::assertStringContainsString($expected, (string) file_get_contents($log));
    }
}
