<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UsernameClaims;
use ChitChat\Auth\UserRepository;
use ChitChat\Moderation\UserReportService;
use ChitChat\Policy\PolicyService;

/**
 * Two-connection races for #164. Workers are separate processes; the test holds
 * a lock, waits until each worker is observably blocked on it, then releases.
 */
final class UsernameChangeConcurrencyTest extends DatabaseTestCase
{
    private const PASSWORD = 'Username-Race-Test-Aa1!';

    public function testARenameWaitsForAnInFlightWriteThatAlsoUpdatesItsOwnRowAndThenRewritesItsNotice(): void
    {
        [$root, $moderator, $member] = $this->people();
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            // An in-flight write: the gate's FOR KEY SHARE, then a mention notice carrying the old name.
            (new PolicyService($this->pdo, $this->config))->assertMayWrite($member);
            $notice = $this->pdo->prepare("INSERT INTO account_notifications (user_id, kind, context_json) VALUES (:user_id, 'mentioned', CAST(:context AS jsonb))");
            $notice->execute(['user_id' => $root->id, 'context' => json_encode(['message_kind' => 'room', 'message_id' => 1,
                'room_id' => null, 'room_name' => 'Lounge', 'broadcast' => false,
                'sender_user_id' => $member->id, 'sender_username' => 'Rude.Name'], JSON_THROW_ON_ERROR)]);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $moderator->id, 'user_id' => $member->id, 'application_name' => 'cc-rename-0']);
            $this->waitForLockWaiters('cc-rename-%', 1);
            // The writer now also updates its own row without a key change; this must not deadlock with the waiting rename.
            $this->pdo->exec("UPDATE users SET share_typing = NOT share_typing WHERE id = {$member->id}");
            $this->pdo->commit();
            [$outcome] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        self::assertSame(200, $outcome['status']);
        $placeholder = $outcome['result']['username'];
        self::assertSame($placeholder, $this->pdo->query("SELECT context_json->>'sender_username' FROM account_notifications WHERE kind = 'mentioned'")?->fetchColumn(),
            'The write committed first, so its notice was rewritten by the rename.');
        $renamed = (int) $this->pdo->query("SELECT MAX(id) FROM realtime_events WHERE event_type = 'user_renamed'")?->fetchColumn();
        self::assertGreaterThan(0, $renamed);
    }

    public function testAChoiceAndARegistrationOfTheSameNameCannotBothWin(): void
    {
        [, $moderator, $member] = $this->people();
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, 'Offensive', null, '127.0.0.2');
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            (new UsernameClaims($this->pdo))->lock('fresh.name');
            $processes[] = $this->worker(['action' => 'choose', 'actor_id' => $member->id, 'username' => 'Fresh.Name', 'application_name' => 'cc-claim-0']);
            $processes[] = $this->worker(['action' => 'register', 'username' => 'FRESH.name', 'application_name' => 'cc-claim-1']);
            $this->waitForLockWaiters('cc-claim-%', 2);
            $this->pdo->commit();
            $outcomes = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        self::assertSame([200, 409], $statuses);
        self::assertContains(['status' => 409, 'error_code' => 'username_taken'], $outcomes);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE username_canonical = 'fresh.name'")?->fetchColumn());
    }

    public function testARepeatedActionAndAChoiceSerializeOnTheAccountRow(): void
    {
        [, $moderator, $member] = $this->people();
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, 'Offensive', null, '127.0.0.2');
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            $hold = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR KEY SHARE');
            $hold->execute(['id' => $member->id]);
            $processes[] = $this->worker(['action' => 'choose', 'actor_id' => $member->id, 'username' => 'Kind.Name', 'application_name' => 'cc-pair-0']);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $moderator->id, 'user_id' => $member->id, 'application_name' => 'cc-pair-1']);
            $this->waitForLockWaiters('cc-pair-%', 2);
            $this->pdo->commit();
            [$choice, $action] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        self::assertSame(200, $choice['status']);
        $row = $this->pdo->query("SELECT username, username_change_previous_canonical FROM users WHERE id = {$member->id}")?->fetch();
        self::assertIsArray($row);
        if ($action['status'] === 200) {
            // The choice won the row first; the second action then replaced the chosen name.
            self::assertSame([$action['result']['username'], 'kind.name'], array_values($row));
        } else {
            self::assertSame(['status' => 409, 'error_code' => 'username_change_already_required'], $action);
            self::assertSame(['Kind.Name', null], array_values($row));
        }
    }

    public function testAnActionWithACaseAndAPlainResolveOfThatCaseCannotBothCloseIt(): void
    {
        [$root, $moderator, $member] = $this->people();
        $case = (new UserReportService($this->pdo, $this->config))->report($root, $member->id, 'offensive_username', null, null, '127.0.0.1');
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            $hold = $this->pdo->prepare('SELECT id FROM moderation_cases WHERE id = :id FOR UPDATE');
            $hold->execute(['id' => $case['id']]);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $moderator->id, 'user_id' => $member->id, 'case_id' => $case['id'], 'application_name' => 'cc-case-0']);
            $processes[] = $this->worker(['action' => 'resolve', 'actor_id' => $moderator->id, 'case_id' => $case['id'], 'application_name' => 'cc-case-1']);
            $this->waitForLockWaiters('cc-case-%', 2);
            $this->pdo->commit();
            [$action, $resolve] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        self::assertSame([200, 409], [min($action['status'], $resolve['status']), max($action['status'], $resolve['status'])]);
        $username = (string) $this->pdo->query("SELECT username FROM users WHERE id = {$member->id}")?->fetchColumn();
        $code = (string) $this->pdo->query("SELECT resolution_code FROM moderation_cases WHERE id = {$case['id']}")?->fetchColumn();
        if ($action['status'] === 200) {
            self::assertSame(['status' => 409, 'error_code' => 'moderation_case_closed'], $resolve);
            self::assertSame('username_change_required', $code);
            self::assertNotSame('Rude.Name', $username);
        } else {
            self::assertSame(['status' => 409, 'error_code' => 'moderation_case_closed'], $action);
            self::assertSame('user_warned', $code);
            self::assertSame('Rude.Name', $username, 'The refused action rolled back completely.');
        }
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser, 2:AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $member = $auth->register('Rude.Name', self::PASSWORD, '127.0.0.3');
        $users = new UserRepository($this->pdo);

        return [$root, $users->findAuthenticatedById($moderator->id) ?? self::fail('Moderator vanished.'), $member];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0:resource, 1:array<int, resource>}
     */
    private function worker(array $input): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/username-change-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function waitForLockWaiters(string $pattern, int $expected): void
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE :pattern AND wait_event_type = 'Lock'");
        self::assertNotFalse($statement);
        $waiting = 0;
        $deadline = microtime(true) + 15;
        do {
            $this->pdo->query('SELECT pg_stat_clear_snapshot()');
            $statement->execute(['pattern' => $pattern]);
            $waiting = (int) $statement->fetchColumn();
            if ($waiting === $expected) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::assertSame($expected, $waiting, 'Every worker must be blocked on the held lock before release.');
    }

    /**
     * @param list<array{0:resource, 1:array<int, resource>}> $processes
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $processes): array
    {
        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame('', $errors, 'A worker failed (a deadlock would surface here).');
            self::assertSame(0, proc_close($process));
            $outcomes[] = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        }

        return $outcomes;
    }

    /** @param list<array{0:resource, 1:array<int, resource>}> $processes */
    private function cleanUp(array $processes): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach ($processes as [$process, $pipes]) {
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
            if (is_resource($process)) {
                // The held lock is released first; only our own child may be stopped.
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }
}
