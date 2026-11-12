<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Room\RoomService;

/**
 * Ownership transfer racing leave, setRole and another transfer (#273). Workers are
 * separate processes; the test holds a lock, queues them in a fixed order, then releases.
 * After every race the room has exactly one owner.
 */
final class RoomOwnershipConcurrencyTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Ownership-Race-Aa1!';

    public function testATransferQueuedBeforeTheTargetLeavesWinsAndTheLeaveIsRefused(): void
    {
        [$owner, , $target, $roomId] = $this->room();
        [$transfer, $leave] = $this->race(
            "SELECT 1 FROM room_members WHERE room_id = {$roomId} AND user_id = {$target->id} FOR NO KEY UPDATE",
            ['action' => 'transfer', 'actor_id' => $owner->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
            ['action' => 'leave', 'actor_id' => $target->id, 'room_id' => $roomId],
        );
        self::assertSame(200, $transfer['status']);
        self::assertSame(['status' => 409, 'error_code' => 'owner_cannot_leave'], $leave);
        self::assertSame([$target->id], $this->owners($roomId));
    }

    public function testATargetWhoLeftFirstCannotBeMadeOwner(): void
    {
        [$owner, , $target, $roomId] = $this->room();
        [$leave, $transfer] = $this->race(
            "SELECT 1 FROM room_members WHERE room_id = {$roomId} AND user_id = {$target->id} FOR NO KEY UPDATE",
            ['action' => 'leave', 'actor_id' => $target->id, 'room_id' => $roomId],
            ['action' => 'transfer', 'actor_id' => $owner->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
        );
        self::assertSame(200, $leave['status']);
        self::assertSame(['status' => 404, 'error_code' => 'membership_not_found'], $transfer);
        self::assertSame([$owner->id], $this->owners($roomId));
    }

    public function testARoleChangeQueuedAfterATransferCannotDemoteTheNewOwner(): void
    {
        [$owner, $admin, $target, $roomId] = $this->room();
        [$transfer, $setRole] = $this->race(
            "SELECT 1 FROM room_members WHERE room_id = {$roomId} AND user_id = {$target->id} FOR NO KEY UPDATE",
            ['action' => 'transfer', 'actor_id' => $owner->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
            ['action' => 'set_role', 'actor_id' => $admin->id, 'room_id' => $roomId, 'target_user_id' => $target->id, 'role' => 'member'],
        );
        self::assertSame(200, $transfer['status']);
        self::assertSame(['status' => 409, 'error_code' => 'owner_role_immutable'], $setRole);
        self::assertSame([$target->id], $this->owners($roomId));
    }

    public function testAMemberRemovalQueuedAfterATransferCannotRemoveTheNewOwner(): void
    {
        [$owner, $admin, $target, $roomId] = $this->room();
        [$transfer, $removal] = $this->race(
            "SELECT 1 FROM room_members WHERE room_id = {$roomId} AND user_id = {$target->id} FOR NO KEY UPDATE",
            ['action' => 'transfer', 'actor_id' => $owner->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
            ['action' => 'remove_member', 'actor_id' => $admin->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
        );
        self::assertSame(200, $transfer['status']);
        self::assertSame(['status' => 409, 'error_code' => 'owner_membership_immutable'], $removal);
        self::assertSame([$target->id], $this->owners($roomId));
    }

    public function testTwoTransfersSerializeAndTheOwnerQueuedSecondIsRefused(): void
    {
        [$owner, $admin, $target, $roomId] = $this->room();
        $other = (new AuthService($this->pdo, $this->config))->register('OtherMember', self::PASSWORD, '127.0.0.5');
        (new RoomService($this->pdo))->join($other, $roomId, '127.0.0.5');
        [$byAdmin, $byOwner] = $this->race(
            "SELECT 1 FROM rooms WHERE id = {$roomId} FOR NO KEY UPDATE",
            ['action' => 'transfer', 'actor_id' => $admin->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
            ['action' => 'transfer', 'actor_id' => $owner->id, 'room_id' => $roomId, 'target_user_id' => $other->id],
        );
        self::assertSame(200, $byAdmin['status']);
        self::assertSame(['status' => 409, 'error_code' => 'ownership_changed'], $byOwner, 'The owner lost the room while queued.');
        self::assertSame([$target->id], $this->owners($roomId));
        self::assertSame('moderator', $this->role($roomId, $owner->id));
    }

    public function testASecondAdministratorTransferQueuedBehindTheFirstIsRefusedAsChanged(): void
    {
        [, $admin, $target, $roomId] = $this->room();
        $auth = new AuthService($this->pdo, $this->config);
        $secondAdmin = $auth->register('SecondAdmin', self::PASSWORD, '127.0.0.6');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$secondAdmin->id}, 'chat_admin')");
        $other = $auth->register('OtherMember', self::PASSWORD, '127.0.0.5');
        (new RoomService($this->pdo))->join($other, $roomId, '127.0.0.5');
        [$first, $second] = $this->race(
            "SELECT 1 FROM rooms WHERE id = {$roomId} FOR NO KEY UPDATE",
            ['action' => 'transfer', 'actor_id' => $admin->id, 'room_id' => $roomId, 'target_user_id' => $target->id],
            ['action' => 'transfer', 'actor_id' => $secondAdmin->id, 'room_id' => $roomId, 'target_user_id' => $other->id],
        );
        self::assertSame(200, $first['status']);
        self::assertSame(['status' => 409, 'error_code' => 'ownership_changed'], $second, 'Decided on an owner that changed while queued.');
        self::assertSame([$target->id], $this->owners($roomId));
        self::assertSame('member', $this->role($roomId, $other->id));
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser, 2:AuthenticatedUser, 3:int} owner, admin, target member, room */
    private function room(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $owner = $auth->register('RoomOwner', self::PASSWORD, '127.0.0.2');
        $admin = $auth->register('ChatAdmin', self::PASSWORD, '127.0.0.3');
        $target = $auth->register('TargetMember', self::PASSWORD, '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin')");
        $owner = $this->reload($owner);
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        // The owner no longer manages every room, so only ownership lets them transfer.
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'chat_admin')");
        $rooms->join($target, $room->id, '127.0.0.4');

        return [$this->reload($owner), $this->reload($admin), $target, $room->id];
    }

    /**
     * Holds `$lockSql` in the test connection, starts the first worker and waits until it is
     * blocked, then the second, then releases.
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     * @return array{0:array<string, mixed>, 1:array<string, mixed>}
     */
    private function race(string $lockSql, array $first, array $second): array
    {
        $this->pdo->beginTransaction();
        $processes = [];
        try {
            $this->pdo->query($lockSql);
            $processes[] = $this->worker($first + ['application_name' => 'cc-own-first']);
            $this->waitForLockWaiters('cc-own-%', 1);
            $processes[] = $this->worker($second + ['application_name' => 'cc-own-second']);
            $this->waitForLockWaiters('cc-own-%', 2);
            $this->pdo->commit();
            $outcomes = $this->outcomes($processes);
        } finally {
            $this->cleanUp($processes);
        }

        return [$outcomes[0], $outcomes[1]];
    }

    /** @return list<int> */
    private function owners(int $roomId): array
    {
        $statement = $this->pdo->query("SELECT user_id FROM room_members WHERE room_id = {$roomId} AND role = 'owner' ORDER BY user_id");
        self::assertNotFalse($statement);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function role(int $roomId, int $userId): string
    {
        return (string) $this->pdo->query("SELECT role FROM room_members WHERE room_id = {$roomId} AND user_id = {$userId}")?->fetchColumn();
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $fresh = (new \ChitChat\Auth\UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0:resource, 1:array<int, resource>}
     */
    private function worker(array $input): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/room-ownership-worker.php'],
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
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }
}
