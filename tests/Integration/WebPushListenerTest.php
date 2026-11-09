<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Database;
use ChitChat\Tests\Support\ScriptedPushTransport;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\WebPush\WebPushDispatcher;
use ChitChat\WebPush\WebPushListener;
use Minishlink\WebPush\VAPID;
use PDO;

final class WebPushListenerTest extends DatabaseTestCase
{
    public function testNotifyIsEmptyCommitOnlyAndDoesNotWakeForPrivacyOrRollback(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $reader = Database::connect($this->config);
        $reader->exec('LISTEN chitchat_push');
        try {
            $insert = $this->pdo->prepare("INSERT INTO account_notifications (user_id, kind) VALUES (:user, :kind)");
            $this->pdo->beginTransaction();
            $insert->execute(['user' => $member->id, 'kind' => 'admin_password_reset']);
            self::assertFalse($reader->pgsqlGetNotify(PDO::FETCH_ASSOC, 30));
            $this->pdo->rollBack();
            self::assertFalse($reader->pgsqlGetNotify(PDO::FETCH_ASSOC, 30));
            $insert->execute(['user' => $member->id, 'kind' => 'privacy_policy_updated']);
            self::assertFalse($reader->pgsqlGetNotify(PDO::FETCH_ASSOC, 30));
            $this->pdo->beginTransaction();
            $insert->execute(['user' => $member->id, 'kind' => 'admin_password_reset']);
            self::assertFalse($reader->pgsqlGetNotify(PDO::FETCH_ASSOC, 30));
            $this->pdo->commit();
            $wake = $reader->pgsqlGetNotify(PDO::FETCH_ASSOC, 500);
            self::assertIsArray($wake);
            self::assertSame('chitchat_push', $wake['message']);
            self::assertSame('', (string) ($wake['payload'] ?? ''));
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $reader->exec('UNLISTEN chitchat_push');
        }
    }

    public function testCommittedNotificationWakesTheLoopBeforeItsSafetySweepWithoutNetwork(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        (PushFixtures::subscriptions($this->pdo))->subscribe($member, 'https://push.example/test', PushFixtures::key(), PushFixtures::auth(), null, str_repeat('a', 64));
        $reader = Database::connect($this->config);
        $config = $this->enabledConfig();
        $transport = new ScriptedPushTransport();
        $stop = false;
        $reasons = [];
        $sent = 0;
        $deadline = hrtime(true) + 4_000_000_000;
        $listener = new WebPushListener($config, static fn (): PDO => $reader,
            static fn (PDO $pdo): WebPushDispatcher => new WebPushDispatcher($pdo, $config, $transport));
        $start = hrtime(true);
        $listener->run(
            static function () use (&$stop, $deadline): bool { return $stop || hrtime(true) >= $deadline; },
            function (string $reason, array $summary) use (&$stop, &$reasons, &$sent, $member): void {
                $reasons[] = $reason;
                if ($reason === 'startup') {
                    $insert = $this->pdo->prepare("INSERT INTO account_notifications (user_id, kind) VALUES (:id, 'admin_password_reset')");
                    $this->pdo->beginTransaction();
                    $insert->execute(['id' => $member->id]);
                    $insert->execute(['id' => $member->id]);
                    $this->pdo->commit();
                }
                if ($reason === 'notify' || $reason === 'backlog') {
                    self::assertSame(1, $summary['sent']);
                    $sent += $summary['sent'];
                    $stop = $sent === 2;
                }
            },
        );
        self::assertTrue($stop, 'The committed insert must wake the listener, not wait fifteen seconds.');
        self::assertSame(['startup', 'notify', 'backlog'], $reasons);
        self::assertCount(2, $transport->calls);
        self::assertLessThan(4.0, (hrtime(true) - $start) / 1_000_000_000);
        self::assertSame([], $reader->query('SELECT pg_listening_channels()')->fetchAll(), 'The connection must UNLISTEN on exit.');
    }

    public function testConnectionLossReconnectsWithBackoffAndRestoresListening(): void
    {
        $config = $this->enabledConfig();
        $transport = new ScriptedPushTransport();
        $connections = [];
        $stop = false;
        $reasons = [];
        $deadline = hrtime(true) + 6_000_000_000;
        $listener = new WebPushListener($config, function () use (&$connections): PDO {
            $connection = Database::connect($this->config, 3);
            $connections[] = $connection;
            return $connection;
        }, static fn (PDO $pdo): WebPushDispatcher => new WebPushDispatcher($pdo, $config, $transport));
        $listener->run(
            static function () use (&$stop, $deadline): bool { return $stop || hrtime(true) >= $deadline; },
            function (string $reason, array $summary) use (&$stop, &$reasons, &$connections): void {
                $reasons[] = $reason;
                if ($reason === 'startup' && count($connections) === 1) {
                    // Terminate only this test's own listener backend, never the server or another suite.
                    $pid = $connections[0]->pgsqlGetPid();
                    $this->pdo->query('SELECT pg_terminate_backend(' . $pid . ')')->fetchColumn();
                } elseif ($reason === 'startup') {
                    $stop = true;
                }
                if ($reason === 'reconnect') {
                    self::assertSame(1, $summary['retry_in_seconds']);
                }
            },
        );
        self::assertTrue($stop);
        self::assertSame(['startup', 'reconnect', 'startup'], $reasons);
        self::assertCount(2, $connections);
        self::assertCount(0, $transport->calls);
    }

    public function testUnixListenerHandlesSigtermCleanlyWhileIdle(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_signal')) {
            self::markTestSkipped('Unix signal proof runs in CI with pcntl; Windows uses console control events.');
        }
        $keys = VAPID::createVapidKeys();
        $environment = array_replace(getenv(), ['WEB_PUSH_VAPID_PUBLIC_KEY' => $keys['publicKey'],
            'WEB_PUSH_VAPID_PRIVATE_KEY' => $keys['privateKey'], 'WEB_PUSH_VAPID_SUBJECT' => 'mailto:admin@example.test']);
        $process = proc_open([PHP_BINARY, 'bin/dispatch-web-push', '--listen'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        try {
            $output = '';
            $deadline = microtime(true) + 10;
            do {
                $output .= stream_get_contents($pipes[1]);
                if (str_contains($output, '"wake":"startup"')) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline && proc_get_status($process)['running']);
            self::assertStringContainsString('"wake":"startup"', $output);
            proc_terminate($process, SIGTERM);
            $deadline = microtime(true) + 5;
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($state['running']);
            self::assertSame(0, $state['exitcode']);
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function enabledConfig(): \ChitChat\Config
    {
        $keys = VAPID::createVapidKeys();
        return $this->configWith(['webPushVapidPublicKey' => $keys['publicKey'], 'webPushVapidPrivateKey' => $keys['privateKey'],
            'webPushVapidSubject' => 'mailto:admin@example.test']);
    }
}
