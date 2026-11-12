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

    public function testAPlainResolveQueuedFirstClosesTheCaseAndTheActionRollsBack(): void
    {
        // Before resolve took the subject's account first, this order deadlocked (40P01):
        // the resolve held the case and needed the account; the action held the account and needed the case.
        [$action, $resolve, $member, $caseId] = $this->raceActionAndResolve(resolveFirst: true);
        self::assertSame(['status' => 200, 'result' => ['case' => 'resolved']], $resolve);
        self::assertSame(['status' => 409, 'error_code' => 'moderation_case_closed'], $action);
        self::assertSame('Rude.Name', (string) $this->pdo->query("SELECT username FROM users WHERE id = {$member->id}")?->fetchColumn(), 'The refused action rolled back completely.');
        self::assertSame('user_warned', (string) $this->pdo->query("SELECT resolution_code FROM moderation_cases WHERE id = {$caseId}")?->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM username_reservations')?->fetchColumn());
    }

    public function testAnActionQueuedFirstClosesTheCaseAndThePlainResolveIsRefused(): void
    {
        [$action, $resolve, $member, $caseId] = $this->raceActionAndResolve(resolveFirst: false);
        self::assertSame(200, $action['status']);
        self::assertSame(['status' => 409, 'error_code' => 'moderation_case_closed'], $resolve);
        self::assertSame($action['result']['username'], (string) $this->pdo->query("SELECT username FROM users WHERE id = {$member->id}")?->fetchColumn());
        self::assertSame('username_change_required', (string) $this->pdo->query("SELECT resolution_code FROM moderation_cases WHERE id = {$caseId}")?->fetchColumn());
    }

    /** @return array{0:array<string, mixed>, 1:array<string, mixed>, 2:AuthenticatedUser, 3:int} */
    private function raceActionAndResolve(bool $resolveFirst): array
    {
        [$root, $moderator, $member] = $this->people();
        $case = (new UserReportService($this->pdo, $this->config))->report($root, $member->id, 'offensive_username', null, null, '127.0.0.1');
        $action = ['action' => 'require', 'actor_id' => $moderator->id, 'user_id' => $member->id, 'case_id' => $case['id'], 'application_name' => 'cc-case-action'];
        $resolve = ['action' => 'resolve', 'actor_id' => $moderator->id, 'case_id' => $case['id'], 'application_name' => 'cc-case-resolve'];
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            $hold = $this->pdo->prepare('SELECT id FROM moderation_cases WHERE id = :id FOR UPDATE');
            $hold->execute(['id' => $case['id']]);
            // Queue the two in a fixed order: the first is observably waiting before the second starts.
            $processes[] = $this->worker($resolveFirst ? $resolve : $action);
            $this->waitForLockWaiters('cc-case-%', 1);
            $processes[] = $this->worker($resolveFirst ? $action : $resolve);
            $this->waitForLockWaiters('cc-case-%', 2);
            $this->pdo->commit();
            [$first, $second] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }

        return $resolveFirst ? [$second, $first, $member, $case['id']] : [$first, $second, $member, $case['id']];
    }

    public function testTwoSuperAdministratorsActingOnEachOtherDoNotDeadlock(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $first = $auth->register('FirstRoot', self::PASSWORD, '127.0.0.1');
        $second = $auth->register('SecondRoot', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$second->id}, 'super_admin')");
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            // Both workers queue on the same held rows; each then needs both accounts.
            $hold = $this->pdo->prepare('SELECT id FROM users WHERE id IN (:first, :second) ORDER BY id FOR KEY SHARE');
            $hold->execute(['first' => $first->id, 'second' => $second->id]);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $first->id, 'user_id' => $second->id, 'application_name' => 'cc-mutual-0']);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $second->id, 'user_id' => $first->id, 'application_name' => 'cc-mutual-1']);
            $this->waitForLockWaiters('cc-mutual-%', 2);
            $this->pdo->commit();
            $outcomes = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        // Account rows are locked in ID order, so the two serialize instead of failing with 40P01.
        self::assertSame([200, 200], array_column($outcomes, 'status'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE username_change_required_at IS NOT NULL')?->fetchColumn());
    }

    public function testAModeratorRemovingAnAvatarAndARenameActingOnEachOtherDoNotDeadlock(): void
    {
        // #248: the avatar path used to lock its target FOR UPDATE and then take the actor's FK KEY SHARE,
        // while "require a new username" holds its target FOR UPDATE and needs the actor's KEY SHARE.
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $superAdmin = $auth->register('SecondRoot', self::PASSWORD, '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$superAdmin->id}, 'super_admin')");
        $this->pdo->exec("UPDATE users SET avatar_key = '" . str_repeat('ab', 32) . "', avatar_updated_at = NOW() WHERE id = {$superAdmin->id}");
        self::assertLessThan($superAdmin->id, $moderator->id);
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            // Hold both first locks so the workers start together: the avatar's lock on its target, the rename's on its target.
            $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE')->execute(['id' => $superAdmin->id]);
            $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR KEY SHARE')->execute(['id' => $moderator->id]);
            $processes[] = $this->worker(['action' => 'remove_avatar', 'actor_id' => $moderator->id, 'user_id' => $superAdmin->id, 'application_name' => 'cc-avatar-0']);
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $superAdmin->id, 'user_id' => $moderator->id, 'application_name' => 'cc-avatar-1']);
            $this->waitForLockWaiters('cc-avatar-%', 2);
            $this->pdo->commit();
            [$removal, $rename] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        self::assertSame(['status' => 200, 'result' => ['removed' => true]], $removal);
        self::assertSame(200, $rename['status']);
        self::assertNull($this->pdo->query("SELECT avatar_key FROM users WHERE id = {$superAdmin->id}")?->fetchColumn());
        self::assertSame($rename['result']['username'], (string) $this->pdo->query("SELECT username FROM users WHERE id = {$moderator->id}")?->fetchColumn());
    }

    public function testRegistrationOfTheRemovedNameRacingTheActionIsRefused(): void
    {
        [, $moderator, $member] = $this->people();
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            (new UsernameClaims($this->pdo))->lock('rude.name');
            $processes[] = $this->worker(['action' => 'require', 'actor_id' => $moderator->id, 'user_id' => $member->id, 'application_name' => 'cc-old-0']);
            $processes[] = $this->worker(['action' => 'register', 'username' => 'RUDE.name', 'application_name' => 'cc-old-1']);
            $this->waitForLockWaiters('cc-old-%', 2);
            $this->pdo->commit();
            [$action, $registration] = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }
        // Whichever claim lock is granted first, the removed name is never handed to a new account.
        self::assertSame(200, $action['status']);
        self::assertSame(['status' => 409, 'error_code' => 'username_taken'], $registration);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM username_reservations WHERE username_canonical = 'rude.name' AND reserved_until IS NULL")?->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE username_canonical = 'rude.name'")?->fetchColumn());
    }

    public function testAGatedWriteNeverWaitsOnItsLoginRow(): void
    {
        // #205: a revocation holds the login row and then the account; a write transaction that
        // touched last_seen while holding the gate's account lock would form a cycle with it.
        [$root, , $member] = $this->people();
        $room = (new \ChitChat\Room\RoomService($this->pdo))->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        (new \ChitChat\Room\RoomService($this->pdo))->join($member, $room->id, '127.0.0.3');
        $login = (new \ChitChat\Auth\LoginSessionRepository($this->pdo))->issue($member);
        $this->pdo->exec("UPDATE login_sessions SET last_seen_at = NOW() - interval '1 hour' WHERE id = '{$login->id}'");
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            $hold = $this->pdo->prepare('SELECT id FROM login_sessions WHERE user_id = :id FOR UPDATE');
            $hold->execute(['id' => $member->id]);
            $processes[] = $this->worker(['action' => 'send', 'actor_id' => $member->id, 'room_id' => $room->id, 'body' => 'Still fine', 'application_name' => 'cc-login-0']);
            // The write completes while the login row stays locked (bounded, so a regression fails rather than hangs).
            [$process, $pipes] = $processes[0];
            $deadline = microtime(true) + 15;
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($state['running'], 'The gated write waited on its login row.');
            self::assertSame(0, $state['exitcode']);
            self::assertSame('', (string) stream_get_contents($pipes[2]));
            $outcome = json_decode((string) stream_get_contents($pipes[1]), true, 8, JSON_THROW_ON_ERROR);
            $this->pdo->commit();
        } finally {
            $this->cleanUp($processes);
        }
        self::assertSame(200, $outcome['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM room_messages WHERE sender_id = {$member->id}")?->fetchColumn());
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
