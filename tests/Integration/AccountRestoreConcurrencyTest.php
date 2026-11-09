<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccountRestoreConcurrencyTest extends DatabaseTestCase
{
    /** @return array{user_id:int,session_version:int} */
    private function closingMember(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('RestoreRoot', 'a very secure password', '192.0.2.1');
        $member = $auth->register('RestoreMember', 'another secure password', '192.0.2.2');
        $this->pdo->exec("INSERT INTO user_roles(user_id,role) VALUES ($member->id,'chat_admin')");
        $member = (new UserRepository($this->pdo))->findAuthenticatedById($member->id);
        self::assertNotNull($member);
        $closure = (new AccountClosureService($this->pdo, $this->config))->request($member, '192.0.2.2');
        return ['user_id' => $member->id, 'session_version' => $closure['session_version']];
    }

    /** @return array<string,array{bool}> */
    public static function pendingOutcomes(): array
    {
        return ['restoration wins' => [false], 'deadline passes during the wait' => [true]];
    }

    #[DataProvider('pendingOutcomes')]
    public function testWebRestoreLocksTheClosureBeforeTheAccountAndRechecksItsDeadline(bool $expireDuringWait): void
    {
        $member = $this->closingMember();
        $id = $member['user_id'];
        if ($expireDuringWait) {
            // Both lifecycle copies agree. The real clock passes the deadline
            // while the worker is blocked; no artificial long sleep is needed.
            $this->pdo->exec("UPDATE users SET closure_finalizes_at = clock_timestamp() + interval '3 seconds' WHERE id = $id");
            $this->pdo->exec("UPDATE account_closures SET finalizes_at = (SELECT closure_finalizes_at FROM users WHERE id = $id) WHERE user_id = $id");
        }
        $directory = TestScratch::directory('web-restore-lock');
        $fixture = $directory . '/fixture.json';
        $pidFile = $directory . '/pid';
        $resultFile = $directory . '/result.json';
        $log = $directory . '/worker.log';
        file_put_contents($pidFile, '');
        file_put_contents($fixture, json_encode(['user_id' => $id, 'pid_file' => $pidFile, 'result_file' => $resultFile], JSON_THROW_ON_ERROR));
        $other = Database::connect($this->config);
        $process = null;
        try {
            $other->beginTransaction();
            $lock = $other->prepare('SELECT id FROM account_closures WHERE user_id = :id AND restored_at IS NULL AND finalized_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $id]);
            self::assertNotFalse($lock->fetchColumn());
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/account-restore-worker.php', $fixture],
                [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $this->waitUntil(static fn (): bool => preg_match('/\A[0-9]+\z/', trim((string) file_get_contents($pidFile))) === 1, 'Worker did not publish its backend PID.');
            $pid = (int) file_get_contents($pidFile);
            $wait = $this->pdo->prepare("SELECT wait_event_type = 'Lock' AND query LIKE '%account_closures%' FROM pg_stat_activity WHERE pid = :pid");
            $this->waitUntil(static function () use ($wait, $pid): bool {
                $wait->execute(['pid' => $pid]);
                return (bool) $wait->fetchColumn();
            }, 'Web restoration must be waiting on the held closure.');
            // If restoration took the user first, this fails immediately with
            // 55P03. It diagnoses the inversion without creating a deadlock.
            $user = $other->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE NOWAIT');
            $user->execute(['id' => $id]);
            self::assertSame($id, (int) $user->fetchColumn());
            if ($expireDuringWait) {
                $deadline = $other->prepare("SELECT clock_timestamp() > closure_finalizes_at + interval '1 second' FROM users WHERE id = :id");
                $this->waitUntil(static function () use ($deadline, $id): bool {
                    $deadline->execute(['id' => $id]);
                    return (bool) $deadline->fetchColumn();
                }, 'The test clock did not pass the restoration deadline.');
            }
            $other->commit();
            $this->waitUntil(static fn (): bool => is_file($resultFile), 'Worker did not finish restoration.');
            $this->waitUntil(static fn (): bool => !proc_get_status($process)['running'], 'Worker did not exit.');
            $result = json_decode((string) file_get_contents($resultFile), true, 16, JSON_THROW_ON_ERROR);
            proc_close($process);
            $process = null;
            if ($expireDuringWait) {
                self::assertSame(['error_code' => 'account_restoration_expired', 'status' => 410, 'in_transaction' => false], $result);
                self::assertSame('closure_pending', $this->pdo->query("SELECT account_state FROM users WHERE id = $id")->fetchColumn());
                self::assertSame($member['session_version'], (int) $this->pdo->query("SELECT session_version FROM users WHERE id = $id")->fetchColumn());
                self::assertSame([], (new UserRepository($this->pdo))->rolesForUser($id));
                self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.closure_restored'")->fetchColumn());
                self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM account_closures WHERE user_id = $id AND restored_at IS NOT NULL")->fetchColumn());
                self::assertSame(1, (new AccountClosureService($this->pdo, $this->config))->finalizeDue());
                self::assertSame('closed', $this->pdo->query("SELECT account_state FROM users WHERE id = $id")->fetchColumn());
            } else {
                self::assertSame(['user_id' => $id, 'session_version' => $member['session_version'] + 1], $result);
                self::assertSame('active', $this->pdo->query("SELECT account_state FROM users WHERE id = $id")->fetchColumn());
                self::assertSame(['chat_admin'], (new UserRepository($this->pdo))->rolesForUser($id));
                $this->assertRestoreFailure($id, 409, 'account_not_pending');
                self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.closure_restored'")->fetchColumn());
            }
        } finally {
            if ($other->inTransaction()) $other->rollBack();
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            TestScratch::remove($directory);
        }
    }

    public function testCommittedFinalizationWinsWithoutRestoringRolesOrChangingTheClosedAccount(): void
    {
        $member = $this->closingMember();
        $id = $member['user_id'];
        $this->pdo->exec("UPDATE users SET closure_requested_at = NOW() - interval '2 days', closure_finalizes_at = NOW() - interval '1 day' WHERE id = $id");
        $this->pdo->exec("UPDATE account_closures SET requested_at = NOW() - interval '2 days', finalizes_at = NOW() - interval '1 day' WHERE user_id = $id");
        self::assertSame(1, (new AccountClosureService($this->pdo, $this->config))->finalizeDue());
        $before = $this->pdo->query("SELECT * FROM users WHERE id = $id")->fetch();
        $this->assertRestoreFailure($id, 410, 'account_closed');
        self::assertSame($before, $this->pdo->query("SELECT * FROM users WHERE id = $id")->fetch());
        self::assertSame([], (new UserRepository($this->pdo))->rolesForUser($id));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.closure_restored'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM account_closures WHERE user_id = $id AND restored_at IS NOT NULL")->fetchColumn());
        self::assertSame(0, (new AccountClosureService($this->pdo, $this->config))->finalizeDue());
    }

    private function assertRestoreFailure(int $userId, int $status, string $code): void
    {
        try {
            (new AccountClosureService($this->pdo, $this->config))->completeRestore($userId, '192.0.2.2');
            self::fail('Restoration unexpectedly succeeded.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status);
            self::assertSame($code, $exception->errorCode);
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    private function waitUntil(callable $ready, string $message): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            if ($ready()) return;
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        self::fail($message);
    }
}
