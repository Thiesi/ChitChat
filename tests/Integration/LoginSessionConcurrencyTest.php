<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Database;

final class LoginSessionConcurrencyTest extends DatabaseTestCase
{
    public function testIssuanceKeepsAnOuterTransactionAndAllowsConcurrentAuditForeignKeyChecks(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $this->pdo->beginTransaction();
        try {
            (new LoginSessionRepository($this->pdo))->issue($member);
            self::assertTrue($this->pdo->inTransaction());
            (new AuditLogger($other))->log($member->id, 'auth.login_lock_fixture', 'user', (string) $member->id, [], '127.0.0.2');
            self::assertSame(1, (int) $other->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_lock_fixture'")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions')->fetchColumn());
    }

    public static function holderKinds(): iterable
    {
        yield 'member' => [false];
        yield 'guest' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('holderKinds')]
    public function testCurrentUserInsideATransactionDoesNotTakeLoginOrGuestActivityLocks(bool $guest): void
    {
        if ($guest) {
            $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
            $member = (new \ChitChat\Auth\GuestService($this->pdo))->start('127.0.0.1');
        } else {
            $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        }
        $_SESSION = [];
        $issued = (new LoginSessionRepository($this->pdo))->issue($member);
        $_SESSION['auth'] = ['user_id' => $member->id, 'session_version' => $member->sessionVersion,
            'login_session_id' => $issued->id, 'push_token' => $issued->token];
        $this->pdo->exec("UPDATE login_sessions SET last_seen_at = NOW() - interval '10 minutes'");
        if ($guest) $this->pdo->exec("UPDATE users SET guest_last_seen_at = NOW() - interval '3 minutes'");
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '200ms'");
        $this->pdo->beginTransaction();
        try {
            self::assertSame($member->id, \ChitChat\Auth\SessionManager::currentUser(new \ChitChat\Auth\UserRepository($this->pdo))?->id);
            $other->beginTransaction();
            self::assertNotFalse($other->query("SELECT id FROM login_sessions WHERE id = '" . $issued->id . "' FOR UPDATE NOWAIT")->fetchColumn());
            self::assertNotFalse($other->query('SELECT id FROM users WHERE id = ' . $member->id . ' FOR UPDATE NOWAIT')->fetchColumn());
        } finally {
            if ($other->inTransaction()) $other->rollBack();
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $_SESSION = [];
        }
    }

    public function testNativeReplacementWaitsForTheLowerAccountBeforeLockingItsTarget(): void
    {
        $_SESSION = [];
        $auth = new AuthService($this->pdo, $this->config);
        $previous = $auth->register('Previous', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');
        $old = (new LoginSessionRepository($this->pdo))->issue($previous);
        $pidFile = \ChitChat\Tests\Support\TestScratch::file('native-issue-pid');
        $fixture = \ChitChat\Tests\Support\TestScratch::file('native-issue-fixture');
        $log = \ChitChat\Tests\Support\TestScratch::file('native-issue-log');
        file_put_contents($fixture, json_encode(['target' => $target->id, 'pid_file' => $pidFile,
            'replaced' => ['id' => $old->id, 'user_id' => $previous->id, 'hash' => hash('sha256', $old->token)]], JSON_THROW_ON_ERROR));
        $blocker = Database::connect($this->config);
        $process = null;
        try {
            $blocker->beginTransaction();
            $blocker->query('SELECT id FROM users WHERE id = ' . $previous->id . ' FOR NO KEY UPDATE');
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/native-login-issue-worker.php', $fixture],
                [0 => ['pipe','r'],1 => ['file',$log,'a'],2 => ['file',$log,'a']], $pipes, dirname(__DIR__, 2));
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
            $wait = $this->pdo->prepare("SELECT wait_event_type = 'Lock' AND query LIKE '%users%' FROM pg_stat_activity WHERE pid = :pid");
            $deadline = hrtime(true) + 5_000_000_000;
            $blocked = false;
            do {
                $wait->execute(['pid' => (int) $pid]);
                $blocked = in_array($wait->fetchColumn(), [true, 't', 1, '1'], true);
                if ($blocked) break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue($blocked, 'Native issuance must wait for the lower replaced account.');
            self::assertNotFalse($this->pdo->query('SELECT id FROM users WHERE id = ' . $target->id . ' FOR NO KEY UPDATE NOWAIT')->fetchColumn(),
                'The target must not be locked ahead of the blocked lower account.');
            $blocker->commit();
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertFalse($status['running']);
            self::assertSame(0, $status['exitcode'], (string) file_get_contents($log));
            $result = json_decode((string) file_get_contents($log), true, 16, JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $result['id']);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NULL AND user_id = ' . $target->id)->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NULL AND user_id = ' . $previous->id)->fetchColumn());
        } finally {
            if ($blocker->inTransaction()) $blocker->rollBack();
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                proc_close($process);
            }
            foreach ([$pidFile,$fixture,$log] as $path) \ChitChat\Tests\Support\TestScratch::remove($path);
            $_SESSION = [];
        }
    }

    public function testRevocationWaitsAtTheAccountBeforeTakingAnyLoginLock(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $logins = new LoginSessionRepository($this->pdo);
        $_SESSION = [];
        $one = $logins->issue($member);
        $two = $logins->issue($member);
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '200ms'");
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM users WHERE id = ' . $member->id . ' FOR NO KEY UPDATE');
            try {
                (new LoginSessionRepository($other))->revoke($member, $two->id, hash('sha256', $two->token), $one->id, '127.0.0.1');
                self::fail('Revocation bypassed the account lock.');
            } catch (\PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            self::assertFalse($other->inTransaction());
            self::assertNotFalse($this->pdo->query("SELECT id FROM login_sessions WHERE id = '" . $one->id . "' FOR UPDATE NOWAIT")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
            $_SESSION = [];
        }
        self::assertSame(1, $logins->revoke($member, $two->id, hash('sha256', $two->token), $one->id, '127.0.0.1'));
    }
}
