<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\IgnoreService;
use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;
use PDO;

/** The room list's unread_mention_count (#266). */
final class RoomUnreadMentionTest extends DatabaseTestCase
{
    private const PASSWORD = 'Unread-Mention-Test-Aa1!';

    public function testUnseenMentionsCountPerJoinedRoomAndReadingTheRoomClearsThemWithoutReadingTheNotice(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($admin, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $garden = $rooms->create($admin, 'garden', 'Garden', '', 'public', 0, 0, '127.0.0.1');
        $elsewhere = $rooms->create($admin, 'elsewhere', 'Elsewhere', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $lounge->id, '127.0.0.2');
        $rooms->join($member, $garden->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);

        $first = $messages->send($admin, $lounge->id, 'Hello @Member');
        $messages->send($admin, $lounge->id, 'Everyone, @room: tea at four');
        $messages->send($admin, $lounge->id, 'No mention here');
        $messages->send($admin, $garden->id, '@Member the roses');
        (new DirectMessageService($this->pdo))->send($admin, $member->id, 'A private @Member');
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'mentioned'"));

        // Never read yet: unread_count stays 0 until the first read, but unseen mentions already show.
        self::assertSame(0, $this->listed($member, $lounge->id)['unread_count']);
        self::assertSame(2, $this->listed($member, $lounge->id)['unread_mention_count'], 'Explicit and @room mentions count.');
        self::assertSame(1, $this->listed($member, $garden->id)['unread_mention_count'], "Only the room's own mentions count.");
        self::assertSame(0, $this->listed($member, $elsewhere->id)['unread_mention_count'], 'A room the account has not joined reports 0.');

        // Reading past the first mention clears it from the count; the notification stays unread.
        (new RoomReadService($this->pdo))->markRead($member, $lounge->id, $first['id']);
        self::assertSame(1, $this->listed($member, $lounge->id)['unread_mention_count']);
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'mentioned' AND read_at IS NULL AND context_json->>'message_kind' = 'room' AND context_json->>'message_id' = '{$first['id']}'",
        ), 'Reading the room does not mark the mention notification read.');

        // Reading the notification clears it too.
        $gardenNotice = $this->scalar(
            "SELECT id FROM account_notifications WHERE user_id = {$member->id} AND kind = 'mentioned' AND context_json->>'room_id' = '{$garden->id}'",
        );
        self::assertSame(1, (new PrivacyNotificationService($this->pdo))->markRead($member, [$gardenNotice]));
        self::assertSame(0, $this->listed($member, $garden->id)['unread_mention_count']);
        self::assertSame(1, $this->listed($member, $lounge->id)['unread_mention_count']);
    }

    public function testDeletedMessagesAndIgnoredSendersDoNotCount(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $loud = $auth->register('Loud', self::PASSWORD, '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $rooms->join($loud, $room->id, '127.0.0.3');
        $messages = new MessageService($this->pdo);

        $removed = $messages->send($admin, $room->id, '@Member oops');
        $messages->send($admin, $room->id, '@Member kept');
        $messages->send($loud, $room->id, '@Member listen');
        $messages->send($loud, $room->id, '@here everyone');
        self::assertSame(4, $this->listed($member, $room->id)['unread_mention_count']);

        $this->pdo->exec("UPDATE room_messages SET deleted_at = NOW() WHERE id = {$removed['id']}");
        self::assertSame(3, $this->listed($member, $room->id)['unread_mention_count'], 'A deleted message no longer counts.');

        // Ignoring later hides mentions that were already notified, as unread_count does.
        (new IgnoreService($this->pdo))->setIgnored($member, $loud->id, true);
        self::assertSame(1, $this->listed($member, $room->id)['unread_mention_count']);
    }

    public function testCountsStopAtTheCap(): void
    {
        [$admin, $member, $room] = $this->memberOfOneRoom();
        $this->broadcasts($room, $admin->id, $member->id, 130);

        self::assertSame(RoomReadService::UNREAD_CAP, $this->listed($member, $room)['unread_mention_count']);
    }

    public function testTheCountUsesThePartialIndexForAnAccountWithManyBroadcasts(): void
    {
        [$admin, $member, $busy] = $this->memberOfOneRoom();
        $quiet = (new RoomService($this->pdo))->create($admin, 'quiet', 'Quiet', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $quiet->id, '127.0.0.2');
        $this->broadcasts($busy, $admin->id, $member->id, 2000);
        $this->pdo->exec("UPDATE account_notifications SET read_at = NOW() WHERE id IN (SELECT id FROM account_notifications ORDER BY id LIMIT 1000)");
        $this->pdo->exec('ANALYZE account_notifications');
        $this->pdo->exec('ANALYZE room_messages');

        $plan = implode("\n", $this->pdo->query(<<<SQL
EXPLAIN SELECT COUNT(*) FROM account_notifications n
WHERE n.user_id = {$member->id} AND n.kind = 'mentioned' AND n.read_at IS NULL
  AND n.context_json->>'message_kind' = 'room' AND n.context_json->>'room_id' = '{$quiet->id}'
SQL)?->fetchAll(PDO::FETCH_COLUMN) ?: []);
        self::assertStringContainsString('account_notifications_unread_room_mentions', $plan);
        self::assertSame(0, $this->listed($member, $quiet->id)['unread_mention_count']);
        self::assertSame(RoomReadService::UNREAD_CAP, $this->listed($member, $busy)['unread_mention_count']);
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser, 2:int} */
    private function memberOfOneRoom(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        return [$admin, $member, $room->id];
    }

    /** Inserts $count @room messages and their mention notifications directly. */
    private function broadcasts(int $roomId, int $senderId, int $userId, int $count): void
    {
        $this->pdo->exec(<<<SQL
WITH sent AS (
    INSERT INTO room_messages (room_id, sender_id, message_type, body)
    SELECT {$roomId}, {$senderId}, 'text', '@room ' || n FROM generate_series(1, {$count}) AS n
    RETURNING id
)
INSERT INTO account_notifications (user_id, kind, context_json)
SELECT {$userId}, 'mentioned', jsonb_build_object('message_kind', 'room', 'message_id', id, 'room_id', {$roomId},
    'room_name', 'Room', 'broadcast', true, 'sender_user_id', {$senderId}, 'sender_username', 'Admin')
FROM sent
SQL);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function listed(AuthenticatedUser $user, int $roomId): array
    {
        foreach ((new RoomService($this->pdo))->list($user) as $room) {
            if ($room['id'] === $roomId) {
                return $room;
            }
        }
        self::fail('The room is not listed.');
    }
}
