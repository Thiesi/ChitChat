<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;

final class RoomReadServiceTest extends DatabaseTestCase
{
    public function testUnreadCountsFollowTheReadPositionOfJoinedRooms(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $reads = new RoomReadService($this->pdo);

        // Before joining nothing is tracked, so nothing counts as unread.
        $messages->send($admin, $room->id, 'Before you came');
        self::assertSame(0, $this->listed($member, $room->id)['unread_count']);
        self::assertNull($this->listed($member, $room->id)['last_read_message_id']);

        $rooms->join($member, $room->id, '127.0.0.2');
        $seen = $messages->send($admin, $room->id, 'Welcome');
        self::assertSame($seen['id'], $reads->markRead($member, $room->id, $seen['id']));

        $first = $messages->send($admin, $room->id, 'One');
        $messages->send($admin, $room->id, 'Two');
        $deleted = $messages->send($admin, $room->id, 'Removed');
        $this->pdo->exec("UPDATE room_messages SET deleted_at = NOW() WHERE id = {$deleted['id']}");
        $messages->send($member, $room->id, 'My own words');
        $listed = $this->listed($member, $room->id);
        self::assertSame(2, $listed['unread_count'], 'Own and deleted messages do not count.');
        self::assertSame($seen['id'], $listed['last_read_message_id']);

        // The position only moves forward, and never past the newest message.
        self::assertSame($first['id'], $reads->markRead($member, $room->id, $first['id']));
        self::assertSame($first['id'], $reads->markRead($member, $room->id, $seen['id']));
        $newest = (int) $this->pdo->query("SELECT MAX(id) FROM room_messages WHERE room_id = {$room->id}")?->fetchColumn();
        self::assertSame($newest, $reads->markRead($member, $room->id, PHP_INT_MAX));
        self::assertSame(0, $this->listed($member, $room->id)['unread_count']);
    }

    public function testCountsStopAtTheCapAndOutsidersCannotMarkRead(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'one more secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        (new RoomReadService($this->pdo))->markRead($member, $room->id, 0);
        $this->pdo->exec(<<<SQL
INSERT INTO room_messages (room_id, sender_id, message_type, body)
SELECT {$room->id}, {$admin->id}, 'text', 'Filler ' || n FROM generate_series(1, 130) AS n
SQL);
        self::assertSame(RoomReadService::UNREAD_CAP, $this->listed($member, $room->id)['unread_count']);

        $this->expectExceptionObject(new ApiException(404, 'room_not_found', 'You are not a member of that room.'));
        (new RoomReadService($this->pdo))->markRead($outsider, $room->id, 1);
    }

    /** @return array<string, mixed> */
    private function listed(\ChitChat\Auth\AuthenticatedUser $user, int $roomId): array
    {
        foreach ((new RoomService($this->pdo))->list($user) as $room) {
            if ($room['id'] === $roomId) {
                return $room;
            }
        }
        self::fail('The room is not listed.');
    }
}
