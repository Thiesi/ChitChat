<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Moderation\ReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class RoomDeletionTest extends DatabaseTestCase
{
    public function testOwnersAndRoomManagersMayDeleteAndRestoreButRoomModeratorsMayNot(): void
    {
        [$admin, $owner, $moderator, $member, $chatAdmin, $roomId] = $this->roomWithStaff();
        $rooms = new RoomService($this->pdo);

        try {
            $rooms->delete($moderator, $roomId, '127.0.0.3');
            self::fail('Expected a room moderator to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        $rooms->delete($owner, $roomId, '127.0.0.2');
        $this->assertRoomHidden($member, $roomId);
        self::assertSame([$roomId], array_column($rooms->listDeleted($owner), 'id'));
        self::assertSame([$roomId], array_column($rooms->listDeleted($chatAdmin), 'id'));
        self::assertSame([], $rooms->listDeleted($moderator));
        self::assertNotNull($rooms->listDeleted($owner)[0]['purge_after'], 'The default grace period has an end.');

        // Every other member hears about it, the one who deleted it does not.
        self::assertSame(
            [$admin->id, $moderator->id, $member->id],
            $this->notifiedUsers('room_deleted'),
        );

        try {
            $rooms->restore($moderator, $roomId, '127.0.0.3');
            self::fail('Expected a room moderator to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
        self::assertSame($roomId, $rooms->restore($chatAdmin, $roomId, '127.0.0.5')->id);
        self::assertSame($roomId, $rooms->get($member, $roomId)->id);
        self::assertSame([], $rooms->listDeleted($owner));
        self::assertContains($member->id, $this->notifiedUsers('room_restored'));

        $rooms->delete($admin, $roomId, '127.0.0.1');
        $this->assertRoomHidden($member, $roomId);
    }

    public function testMaintenanceRemovesARoomAfterItsGracePeriodButKeepsModerationEvidence(): void
    {
        [, $owner, , $member, , $roomId] = $this->roomWithStaff();
        $message = (new MessageService($this->pdo))->send($member, $roomId, 'Reported before the room went away.');
        (new ReportService($this->pdo))->reportRoomMessage($owner, $message['id'], 'harassment', null, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $rooms->delete($owner, $roomId, '127.0.0.2');

        // Within the grace period nothing is removed.
        $cleanup = new CleanupService($this->pdo, $this->config);
        self::assertSame(0, $cleanup->run(false)['purged_rooms']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM rooms WHERE id = {$roomId}"));

        $this->pdo->exec("UPDATE rooms SET deleted_at = NOW() - INTERVAL '31 days' WHERE id = {$roomId}");
        self::assertSame(1, $cleanup->run(true)['purged_rooms'], 'A dry run reports the purge.');
        self::assertSame(1, $cleanup->run(false)['purged_rooms']);

        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM rooms WHERE id = {$roomId}"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM room_messages WHERE room_id = {$roomId}"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM room_members WHERE room_id = {$roomId}"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'room.purged' AND subject_id = '{$roomId}'"));

        // The case and its evidence snapshot outlive the room.
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM moderation_cases WHERE room_id IS NULL'));
        self::assertSame(1, $this->countRows(
            "SELECT COUNT(*) FROM moderation_reports WHERE evidence_body = 'Reported before the room went away.'",
        ));
        try {
            $rooms->restore($owner, $roomId, '127.0.0.2');
            self::fail('Expected a purged room to be gone for good.');
        } catch (ApiException $exception) {
            self::assertSame('room_not_found', $exception->errorCode);
        }
    }

    public function testAGracePeriodOfZeroKeepsDeletedRooms(): void
    {
        [, $owner, , , , $roomId] = $this->roomWithStaff();
        $rooms = new RoomService($this->pdo);
        $rooms->delete($owner, $roomId, '127.0.0.2');
        $this->pdo->exec("UPDATE rooms SET deleted_at = NOW() - INTERVAL '400 days' WHERE id = {$roomId}");
        $this->pdo->exec('UPDATE system_settings SET deleted_room_grace_days = 0 WHERE id = 1');

        self::assertNull($rooms->listDeleted($owner)[0]['purge_after']);
        self::assertSame(0, (new CleanupService($this->pdo, $this->config))->run(false)['purged_rooms']);
        self::assertSame($roomId, $rooms->restore($owner, $roomId, '127.0.0.2')->id);
    }

    /** @return array{AuthenticatedUser, AuthenticatedUser, AuthenticatedUser, AuthenticatedUser, AuthenticatedUser, int} */
    private function roomWithStaff(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $owner = $auth->register('Owner', 'another secure password', '127.0.0.2');
        $moderator = $auth->register('Moderator', 'third secure password', '127.0.0.3');
        $member = $auth->register('Member', 'fourth secure password', '127.0.0.4');
        $chatAdmin = $auth->register('ChatAdmin', 'fifth secure password', '127.0.0.5');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin'), ({$chatAdmin->id}, 'chat_admin')");
        $users = new UserRepository($this->pdo);
        $owner = $users->findAuthenticatedById($owner->id) ?? self::fail('Owner vanished.');
        $chatAdmin = $users->findAuthenticatedById($chatAdmin->id) ?? self::fail('Chat Admin vanished.');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        // The owner created it as a Chat Admin; the role is not what lets them delete it below.
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $owner = $users->findAuthenticatedById($owner->id) ?? self::fail('Owner vanished.');
        foreach ([$admin, $moderator, $member] as $user) {
            $rooms->join($user, $room->id, '127.0.0.9');
        }
        $rooms->setRole($owner, $room->id, $moderator->id, 'moderator', '127.0.0.2');

        return [$admin, $owner, $moderator, $member, $chatAdmin, $room->id];
    }

    private function assertRoomHidden(AuthenticatedUser $viewer, int $roomId): void
    {
        try {
            (new RoomService($this->pdo))->get($viewer, $roomId);
            self::fail('Expected the deleted room to be hidden.');
        } catch (ApiException $exception) {
            self::assertSame('room_not_found', $exception->errorCode);
        }
    }

    /** @return list<int> */
    private function notifiedUsers(string $kind): array
    {
        $statement = $this->pdo->query("SELECT user_id FROM account_notifications WHERE kind = '{$kind}' ORDER BY user_id");
        return array_map('intval', $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function countRows(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
