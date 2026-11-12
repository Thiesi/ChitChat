<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;

/** A membership starts with a read position, so unread counts work without a read call (#268). */
final class RoomReadStartTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Read-Start-Test-Aa1!';

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testJoiningStartsAtTheNewestMessageAndLaterMessagesCountWithoutARead(): void
    {
        [$owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $messages->send($owner, $room->id, 'Before you came');
        $newest = $messages->send($owner, $room->id, 'Still before');

        $rooms->join($member, $room->id, '127.0.0.2');
        self::assertSame(['unread_count' => 0, 'last_read_message_id' => $newest['id']], $this->counts($member, $room->id));

        $messages->send($owner, $room->id, 'After you came');
        self::assertSame(1, $this->counts($member, $room->id)['unread_count']);

        // A repeated join is a no-op and keeps the position.
        $rooms->join($member, $room->id, '127.0.0.2');
        self::assertSame(['unread_count' => 1, 'last_read_message_id' => $newest['id']], $this->counts($member, $room->id));
    }

    public function testACreatorCountsTheFirstMessagesInTheirNewRoom(): void
    {
        [$owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        self::assertSame(['unread_count' => 0, 'last_read_message_id' => 0], $this->counts($owner, $room->id));

        $rooms->join($member, $room->id, '127.0.0.2');
        (new MessageService($this->pdo))->send($member, $room->id, 'First!');
        self::assertSame(1, $this->counts($owner, $room->id)['unread_count']);
    }

    public function testAcceptingAnInvitationAndAGuestJoinBothStartAPosition(): void
    {
        [$owner, $member] = $this->people();
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $rooms = new RoomService($this->pdo);
        $private = $rooms->create($owner, 'backroom', 'Back room', '', 'private', 0, 0, '127.0.0.1');
        $lobby = $rooms->create($owner, 'lobby', 'Lobby', '', 'public', 0, 0, '127.0.0.1', 'write');
        $messages = new MessageService($this->pdo);
        $inPrivate = $messages->send($owner, $private->id, 'Old news');
        $inLobby = $messages->send($owner, $lobby->id, 'Welcome, guests');

        $rooms->invite($owner, $private->id, $member->id, '127.0.0.1');
        $rooms->join($member, $private->id, '127.0.0.2');
        self::assertSame($inPrivate['id'], $this->counts($member, $private->id)['last_read_message_id']);

        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $rooms->join($guest, $lobby->id, '10.0.0.1');
        self::assertSame($inLobby['id'], $this->counts($guest, $lobby->id)['last_read_message_id']);
        $messages->send($owner, $lobby->id, 'Hello, guest');
        self::assertSame(1, $this->counts($guest, $lobby->id)['unread_count']);
    }

    public function testRejoiningDoesNotCountTheTimeAway(): void
    {
        [$owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $rooms->join($member, $room->id, '127.0.0.2');
        $messages->send($owner, $room->id, 'Seen as unread while a member');
        $rooms->leave($member, $room->id, '127.0.0.2');
        for ($i = 1; $i <= 3; $i++) {
            $away = $messages->send($owner, $room->id, "While you were away {$i}");
        }

        $rooms->join($member, $room->id, '127.0.0.2');
        self::assertSame(['unread_count' => 0, 'last_read_message_id' => $away['id']], $this->counts($member, $room->id));
    }

    public function testTheBackfillStartsMembersWithoutAPositionAndLeavesOthersAlone(): void
    {
        [$owner, $member] = $this->people();
        $third = (new AuthService($this->pdo, $this->config))->register('Third', self::PASSWORD, '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $empty = $rooms->create($owner, 'empty', 'Empty', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $rooms->join($third, $room->id, '127.0.0.3');
        $rooms->join($member, $empty->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);
        $read = $messages->send($owner, $room->id, 'One');
        $newest = $messages->send($owner, $room->id, 'Two');
        (new RoomReadService($this->pdo))->markRead($third, $room->id, $read['id']);
        // Memberships from before this change had no position.
        $this->pdo->exec("DELETE FROM room_reads WHERE user_id = {$member->id}");

        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0057_room_read_start.sql'));

        self::assertSame(['unread_count' => 0, 'last_read_message_id' => $newest['id']], $this->counts($member, $room->id));
        self::assertSame(['unread_count' => 0, 'last_read_message_id' => 0], $this->counts($member, $empty->id));
        self::assertSame(['unread_count' => 1, 'last_read_message_id' => $read['id']], $this->counts($third, $room->id), 'An existing position is unchanged.');
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::PASSWORD, '127.0.0.2');

        return [$owner, $member];
    }

    /** @return array{unread_count:mixed, last_read_message_id:mixed} */
    private function counts(AuthenticatedUser $user, int $roomId): array
    {
        foreach ((new RoomService($this->pdo))->list($user) as $room) {
            if ($room['id'] === $roomId) {
                return ['unread_count' => $room['unread_count'], 'last_read_message_id' => $room['last_read_message_id']];
            }
        }
        self::fail('The room is not listed.');
    }
}
