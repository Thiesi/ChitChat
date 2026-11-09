<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\Tests\Support\ScriptedPushTransport;
use ChitChat\WebPush\PushAddressPolicy;
use ChitChat\WebPush\PushDestinationGuard;
use ChitChat\WebPush\PushDnsResolver;
use ChitChat\WebPush\PushDeliveryQueue;
use ChitChat\WebPush\PushNotification;
use ChitChat\WebPush\PushSendResult;
use ChitChat\WebPush\PushSubscriptionService;
use ChitChat\WebPush\WebPushDispatcher;
use DateTimeImmutable;
use Minishlink\WebPush\VAPID;
use PDOException;

final class PushLifecycleTest extends DatabaseTestCase
{
    private function member(string $name = 'Member'): AuthenticatedUser
    {
        return (new AuthService($this->pdo, $this->config))->register($name, 'a very secure password', '127.0.0.1');
    }

    public function testSameSessionRefreshSkipsDnsAndManagementButRotationChangesBinding(): void
    {
        $actor = $this->member();
        $dns = new class implements PushDnsResolver {
            public int $calls = 0;
            public function resolve(string $host): array { ++$this->calls; return ['8.8.8.8']; }
        };
        $service = new PushSubscriptionService($this->pdo, new PushDestinationGuard($dns, new PushAddressPolicy()),
            new RateLimiter($this->pdo, $this->config->rateLimits));
        $first = $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(), PushFixtures::auth(), 'One', PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        for ($i = 0; $i < 25; ++$i) {
            self::assertSame($first, $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(), PushFixtures::auth(), 'Two', PushFixtures::login($this->pdo, $actor, str_repeat('a', 64))));
        }
        self::assertSame(1, $dns->calls);
        self::assertSame(1, (int) $this->pdo->query("SELECT attempt_count FROM request_rate_limits WHERE scope = 'push_subscription_management'")->fetchColumn());
        $rotated = $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(1), PushFixtures::auth(1), 'Two', PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        self::assertSame($first['id'], $rotated['id']);
        self::assertNotSame($first['binding'], $rotated['binding']);
        self::assertSame(2, $dns->calls);
        self::assertSame(2, (int) $this->pdo->query('SELECT generation FROM push_subscriptions')->fetchColumn());
        $newSession = $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(1), PushFixtures::auth(1), 'Two', PushFixtures::login($this->pdo, $actor, str_repeat('b', 64)));
        self::assertNotSame($rotated['binding'], $newSession['binding']);
        $listed = $service->list($actor, PushFixtures::login($this->pdo, $actor, str_repeat('b', 64)));
        self::assertTrue($listed[0]['current_session']);
        foreach (['endpoint', 'auth_key', 'p256dh_key', 'session_binding_hash', 'delivery_binding'] as $secret) {
            self::assertArrayNotHasKey($secret, $listed[0]);
        }
    }

    public function testOwnershipNeverTransfersAndFailedAttemptsRemainCounted(): void
    {
        $owner = $this->member('Owner');
        $other = $this->member('Other');
        $service = new PushSubscriptionService($this->pdo, PushFixtures::guard(), new RateLimiter($this->pdo, $this->config->rateLimits));
        $registration = $service->subscribe($owner, 'https://push.example/shared', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $owner, str_repeat('a', 64)));
        try {
            $service->subscribe($other, 'https://push.example/shared', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $other, str_repeat('b', 64)));
            self::fail('Endpoint ownership transferred.');
        } catch (ApiException $exception) {
            self::assertSame('push_subscription_conflict', $exception->errorCode);
        }
        self::assertCount(1, $service->list($owner));
        self::assertSame([], $service->list($other));
        self::assertSame(2, (int) $this->pdo->query("SELECT SUM(attempt_count) FROM request_rate_limits WHERE scope = 'push_subscription_management'")->fetchColumn());
        $service->revoke($other, $registration['id']);
        self::assertCount(1, $service->list($owner));
    }

    public function testDeviceCapAndAtomicEndpointReplacementThenRevokeById(): void
    {
        $actor = $this->member();
        $service = PushFixtures::subscriptions($this->pdo);
        $first = null;
        for ($i = 0; $i < PushSubscriptionService::MAX_SUBSCRIPTIONS; ++$i) {
            $registration = $service->subscribe($actor, 'https://push.example/' . $i, PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
            $first ??= $registration;
        }
        try {
            $service->subscribe($actor, 'https://push.example/overflow', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
            self::fail('Cap was exceeded.');
        } catch (ApiException $exception) {
            self::assertSame('push_subscription_limit', $exception->errorCode);
        }
        $replacement = $service->subscribe($actor, 'https://push.example/new-endpoint', PushFixtures::key(1), PushFixtures::auth(1), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)), $first['id']);
        self::assertCount(20, $service->list($actor));
        self::assertNotSame($first['id'], $replacement['id']);
        $service->revoke($actor, $replacement['id']);
        self::assertCount(19, $service->list($actor));
    }

    public function testLogoutRemovesItsOwnSessionAndLegacyRowsButKeepsOtherBoundDevices(): void
    {
        $actor = $this->member();
        $service = PushFixtures::subscriptions($this->pdo);
        foreach (['own' => 'a', 'other' => 'b', 'legacy' => 'c'] as $path => $session) {
            $service->subscribe($actor, 'https://push.example/' . $path, PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat($session, 64)));
        }
        $this->pdo->exec("UPDATE push_subscriptions SET session_binding_hash = NULL, delivery_binding = NULL WHERE endpoint LIKE '%/legacy'");
        $service->logout($actor, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        self::assertCount(1, $service->list($actor));
        self::assertSame('https://push.example/other', $this->pdo->query('SELECT endpoint FROM push_subscriptions')->fetchColumn());
        $this->pdo->exec('UPDATE users SET session_version = session_version + 1');
        try {
            $service->subscribe($actor, 'https://push.example/new', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
            self::fail('Stale session registered push.');
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->status);
        }
    }

    public function testAccountLockSerializesRegistrationBeforeTheDeviceCount(): void
    {
        $actor = $this->member();
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '200ms'");
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT id FROM users WHERE id = ' . $actor->id . ' FOR NO KEY UPDATE');
        try {
            PushFixtures::subscriptions($other)->subscribe($actor, 'https://push.example/new', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
            self::fail('Registration bypassed the account lock.');
        } catch (PDOException $exception) {
            self::assertSame('55P03', $exception->getCode());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
    }

    public function testPayloadIdentityIsStableOnRetryAndSessionInvalidationSuppressesPendingDevices(): void
    {
        $actor = $this->member();
        $service = PushFixtures::subscriptions($this->pdo);
        $registration = $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        $id = (int) $this->pdo->query("INSERT INTO account_notifications (user_id, kind) VALUES ({$actor->id}, 'admin_password_reset') RETURNING id")->fetchColumn();
        $keys = VAPID::createVapidKeys();
        $config = $this->configWith(['webPushVapidPublicKey' => $keys['publicKey'], 'webPushVapidPrivateKey' => $keys['privateKey'], 'webPushVapidSubject' => 'mailto:admin@example.test']);
        $now = new DateTimeImmutable();
        $transport = new ScriptedPushTransport([new PushSendResult('retry', 503, 120), new PushSendResult('retry', 503, 120)]);
        $worker = new WebPushDispatcher($this->pdo, $config, $transport, static function () use (&$now): DateTimeImmutable { return $now; });
        $worker->dispatch();
        $now = $now->modify('+121 seconds');
        $worker->dispatch();
        self::assertCount(2, $transport->calls);
        $payload = json_decode($transport->calls[0]['payload'], true, 16, JSON_THROW_ON_ERROR);
        $retry = json_decode($transport->calls[1]['payload'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($id, $payload['notification_id']);
        self::assertSame('admin_password_reset', $payload['kind']);
        self::assertSame($registration['binding'], $payload['binding']);
        self::assertSame($payload['event_id'], $retry['event_id']);
        self::assertLessThanOrEqual(64, strlen($payload['event_id']));
        self::assertLessThan(3000, strlen($transport->calls[0]['payload']));
        $this->pdo->exec('UPDATE users SET session_version = session_version + 1');
        $now = $now->modify('+121 seconds');
        self::assertSame(1, $worker->dispatch()['skipped_revoked']);
        self::assertCount(2, $transport->calls);
    }

    public function testRebindingNeverReplaysUnmaterializedHistoricalEvents(): void
    {
        $actor = $this->member();
        $service = PushFixtures::subscriptions($this->pdo);
        $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        $this->pdo->exec("INSERT INTO account_notifications (user_id, kind) VALUES ({$actor->id}, 'admin_password_reset')");
        $service->subscribe($actor, 'https://push.example/device', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('b', 64)));
        $queue = new PushDeliveryQueue($this->pdo);
        $summary = $queue->materialize(10, $queue->now(), static fn (PushNotification $notification): ?string => null);
        self::assertSame(1, $summary['skipped_no_subscriptions']);
    }

    public function testReplacementRetryAfterLostResponseKeepsTheAcknowledgedGeneration(): void
    {
        $actor = $this->member();
        $service = PushFixtures::subscriptions($this->pdo);
        $old = $service->subscribe($actor, 'https://push.example/old', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        $new = $service->subscribe($actor, 'https://push.example/new', PushFixtures::key(1), PushFixtures::auth(1), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)), $old['id']);
        self::assertSame($new, $service->subscribe($actor, 'https://push.example/new', PushFixtures::key(1), PushFixtures::auth(1), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)), $old['id']));
        self::assertSame(1, (int) $this->pdo->query('SELECT generation FROM push_subscriptions')->fetchColumn());
        self::assertCount(1, $service->list($actor));
    }

    public function testExpiredDeliveryDoesNotLockTheLedgerBeforeConcurrentLocalRevocation(): void
    {
        $actor = $this->member();
        $registration = PushFixtures::subscriptions($this->pdo)->subscribe($actor, 'https://push.example/device',
            PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $actor, str_repeat('a', 64)));
        $this->pdo->exec("INSERT INTO account_notifications (user_id, kind) VALUES ({$actor->id}, 'admin_password_reset')");
        $queue = new PushDeliveryQueue($this->pdo);
        $queue->materialize(10, $queue->now(), static fn (PushNotification $notification): ?string => null);
        $log = \ChitChat\Tests\Support\TestScratch::file('push-expiry-lock');
        $code = <<<'PHP'
require $argv[1];
$pdo = \ChitChat\Database::connect(\ChitChat\Config::fromEnvironment());
$pdo->exec("SET application_name = 'cc_push_expiry_lock_test'");
$queue = new \ChitChat\WebPush\PushDeliveryQueue($pdo);
$claim = $queue->claim($queue->now());
if ($claim === null) { throw new RuntimeException('Missing fixture claim.'); }
$count = $queue->finish($claim, 'expired', 'subscription_expired', $queue->now(), 410, null, true);
echo "finished:" . $count;
PHP;
        $script = \ChitChat\Tests\Support\TestScratch::file('push-expiry-child');
        file_put_contents($script, "<?php\n" . $code);
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT id FROM push_subscriptions WHERE id = ' . $registration['id'] . ' FOR UPDATE');
        $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2) . '/vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $this->pdo->query('SELECT pg_stat_clear_snapshot()');
                $waiting = $this->pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'cc_push_expiry_lock_test' AND wait_event_type = 'Lock'")->fetchColumn();
                if ((int) $waiting > 0) break;
                usleep(10_000);
            } while (hrtime(true) < $deadline && proc_get_status($process)['running']);
            self::assertSame(1, (int) $waiting, 'Expiry worker must be waiting for the held subscription: ' . (string) file_get_contents($log));
            $this->pdo->exec("SET LOCAL lock_timeout = '500ms'");
            $this->pdo->exec('DELETE FROM push_subscriptions WHERE id = ' . $registration['id']);
            $this->pdo->commit();
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $output = file_get_contents($log);
                if (is_string($output) && str_contains($output, 'finished:0')) break;
                usleep(10_000);
            } while (hrtime(true) < $deadline && proc_get_status($process)['running']);
            self::assertStringContainsString('finished:0', (string) file_get_contents($log));
            self::assertSame('expired', $this->pdo->query('SELECT state FROM push_deliveries')->fetchColumn());
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            \ChitChat\Tests\Support\TestScratch::remove($log);
            \ChitChat\Tests\Support\TestScratch::remove($script);
        }
    }
}
