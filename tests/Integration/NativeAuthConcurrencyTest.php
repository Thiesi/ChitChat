<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthConcurrencyTest extends DatabaseTestCase
{
    public function testRestoreWaitsForTheClosureBeforeTakingTheUserLockAndThenCompletesOnce(): void
    {
        $session = new TestSession();
        $session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $member = $auth->register('Member', 'another secure password', '192.0.2.2');
        (new AccountClosureService($this->pdo, $this->config))->request($member, '192.0.2.2');
        $member = (new UserRepository($this->pdo))->findClosurePendingById($member->id);
        self::assertNotNull($member);
        $changes = ['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]];
        $config = $this->configWith($changes);
        $rows = new NativeAuthRepository($this->pdo);
        $rows->statement("INSERT INTO user_identities(user_id,provider,subject) VALUES (:id,'google','private-subject')", ['id' => $member->id]);
        $state = NativeConfig::token();
        $start = (new NativeAuthTransactions($this->pdo, $config))->start([
            'client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => 'restore', 'state' => $state,
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'], null, '192.0.2.1');
        $rows->stageProof($start['transaction_id'], 'consent', $member->id, $member->sessionVersion,
            ['subject' => 'private-subject', 'auth_time' => null, 'started_at' => time()], null);
        $code = NativeConfig::token();
        $rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = LEAST(expires_at, clock_timestamp() + interval '90 seconds') WHERE id = :id",
            ['id' => $start['transaction_id'], 'hash' => hash('sha256', $code)]);
        $fixture = TestScratch::file('native-lock-fixture');
        $sessions = TestScratch::directory('native-lock-session');
        $log = TestScratch::file('native-lock-worker-log');
        $pidFile = TestScratch::file('native-lock-worker-pid');
        file_put_contents($fixture, json_encode(['config' => $changes, 'sessions' => $sessions, 'pid_file' => $pidFile, 'session' => $_SESSION,
            'grant' => ['transaction_id' => $start['transaction_id'], 'code' => $code, 'state' => $state,
                'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk']], JSON_THROW_ON_ERROR));
        $other = Database::connect($config);
        $process = null;
        try {
            $other->beginTransaction();
            $lock = $other->prepare('SELECT id FROM account_closures WHERE user_id = :id FOR UPDATE');
            $lock->execute(['id' => $member->id]);
            self::assertNotFalse($lock->fetchColumn());
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/native-exchange-worker.php', $fixture],
                [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $pid = '';
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $pid = trim((string) file_get_contents($pidFile));
                if ($pid !== '') break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertMatchesRegularExpression('/\A[0-9]+\z/', $pid);
            $wait = $this->pdo->prepare("SELECT wait_event_type = 'Lock' AND query LIKE '%account_closures%' FROM pg_stat_activity WHERE pid = :pid");
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $wait->execute(['pid' => (int) $pid]);
                $waiting = $wait->fetchColumn();
                if ($waiting) break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue((bool) $waiting, 'Exchange should be waiting for the held closure.');
            // A finalizer can now lock this account without deadlocking the waiting restore.
            $user = $other->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE NOWAIT');
            $user->execute(['id' => $member->id]);
            self::assertSame($member->id, (int) $user->fetchColumn());
            $other->commit();
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $output = (string) file_get_contents($log);
                $running = proc_get_status($process)['running'];
                if (!$running) break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertFalse($running, 'Native restore worker did not finish.');
            $output = (string) file_get_contents($log);
            self::assertSame(['outcome' => 'completed', 'user_id' => $member->id], json_decode($output, true), $output);
            proc_close($process);
            $process = null;
            self::assertSame('completed', $rows->require($start['transaction_id'])['outcome']);
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.closure_restored'")->fetchColumn());
        } finally {
            if ($other->inTransaction()) $other->rollBack();
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            TestScratch::remove($pidFile);
            TestScratch::remove($log);
            TestScratch::remove($fixture);
            TestScratch::remove($sessions);
            $session->stop();
        }
    }
}