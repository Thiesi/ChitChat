<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\IgnoreService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;

final class IgnoreServiceTest extends DatabaseTestCase
{
    public function testIgnoringSilencesMentionsAndPingsForTheIgnorerOnly(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $other = $auth->register('Other', 'one more secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $rooms->join($other, $room->id, '127.0.0.3');
        $ignores = new IgnoreService($this->pdo);

        $ignores->setIgnored($member, $admin->id, true);
        $ignores->setIgnored($member, $admin->id, true);
        self::assertSame([$admin->id], $ignores->ignoredBy($member->id));
        self::assertTrue($ignores->isIgnoring($member->id, $admin->id));
        self::assertFalse($ignores->isIgnoring($admin->id, $member->id), 'Ignoring is one-way.');

        $messages = new MessageService($this->pdo);
        $messages->send($admin, $room->id, 'Hello @Member and @Other');
        (new PingService($this->pdo))->send($admin, $room->id, 'Member', 'Over here');
        // The ping itself still exists, so the sender cannot tell it was ignored.
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM room_pings WHERE target_id = {$member->id}"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id}"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$other->id} AND kind = 'mentioned'"));

        // Their messages do not count as unread either.
        $reads = new RoomReadService($this->pdo);
        $reads->markRead($member, $room->id, 0);
        $messages->send($admin, $room->id, 'Unread for nobody who ignores me');
        $messages->send($other, $room->id, 'Unread for Member');
        self::assertSame(1, $reads->forUser($member->id)[$room->id]['unread_count']);

        $ignores->setIgnored($member, $admin->id, false);
        self::assertSame([], $ignores->ignoredBy($member->id));
        $messages->send($admin, $room->id, 'Hello again @Member');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'mentioned'"));
    }

    public function testNobodyIgnoresThemselvesOrAMissingAccount(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'another secure password', '127.0.0.2');
        $ignores = new IgnoreService($this->pdo);
        try {
            $ignores->setIgnored($member, $member->id, true);
            self::fail('Expected self-ignore to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('validation_error', $exception->errorCode);
        }
        $this->expectExceptionObject(new ApiException(404, 'user_not_found', 'User not found.'));
        $ignores->setIgnored($member, 999_999, true);
    }

    private function countRows(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }
}
