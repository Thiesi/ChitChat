<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use DateTimeImmutable;

final class MessageServiceTest extends DatabaseTestCase
{
    public function testMembersCanSendTextAndEmoteMessagesInOrder(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $guest = $auth->register('Guest', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, '127.0.0.1');
        $rooms->join($guest, $room->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);

        $first = $messages->send($guest, $room->id, 'Hello');
        $second = $messages->send($guest, $room->id, '/me waves');
        $history = $messages->history($guest, $room->id);

        self::assertSame([$first['id'], $second['id']], array_column($history, 'id'));
        self::assertSame(['text', 'emote'], array_column($history, 'type'));
        self::assertSame(['Hello', 'waves'], array_column($history, 'body'));
    }

    public function testNonMemberCanReadPublicHistoryButCannotSend(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $guest = $auth->register('Guest', 'another secure password', '127.0.0.2');
        $room = (new RoomService($this->pdo))->create(
            $admin,
            'general',
            'General',
            '',
            'public',
            0,
            '127.0.0.1',
        );
        $messages = new MessageService($this->pdo);
        $messages->send($admin, $room->id, 'Welcome');

        self::assertCount(1, $messages->history($guest, $room->id));

        try {
            $messages->send($guest, $room->id, 'Hello');
            self::fail('Expected membership requirement.');
        } catch (ApiException $exception) {
            self::assertSame('membership_required', $exception->errorCode);
        }
    }

    public function testMinimumAgeAlsoProtectsPublicHistory(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $minorDate = (new DateTimeImmutable('today'))->modify('-15 years')->format('Y-m-d');
        $minor = $auth->register('Minor', 'another secure password', '127.0.0.2', $minorDate);
        $room = (new RoomService($this->pdo))->create(
            $admin,
            'adults',
            'Adults',
            '',
            'public',
            18,
            '127.0.0.1',
        );
        $messages = new MessageService($this->pdo);
        $messages->send($admin, $room->id, 'Adults only');

        try {
            $messages->history($minor, $room->id);
            self::fail('Expected minimum-age history rejection.');
        } catch (ApiException $exception) {
            self::assertSame('minimum_age_not_met', $exception->errorCode);
        }
    }

    public function testRoomModeratorCanSoftDeleteMessage(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $moderator = $auth->register('Moderator', 'another secure password', '127.0.0.2');
        $member = $auth->register('Member', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, '127.0.0.1');
        $rooms->join($moderator, $room->id, '127.0.0.2');
        $rooms->join($member, $room->id, '127.0.0.3');
        $rooms->setRole($admin, $room->id, $moderator->id, 'moderator', '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $message = $messages->send($member, $room->id, 'Delete me');

        $messages->delete($moderator, $message['id'], '127.0.0.2');
        $history = $messages->history($member, $room->id);

        self::assertTrue($history[0]['deleted']);
        self::assertNull($history[0]['body']);
        $auditResult = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'room.message_deleted'");
        self::assertNotFalse($auditResult);
        self::assertSame(1, (int) $auditResult->fetchColumn());
    }

    public function testUnknownCommandIsRejected(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $room = (new RoomService($this->pdo))->create(
            $admin,
            'general',
            'General',
            '',
            'public',
            0,
            '127.0.0.1',
        );

        try {
            (new MessageService($this->pdo))->send($admin, $room->id, '/dance');
            self::fail('Expected unknown command rejection.');
        } catch (ApiException $exception) {
            self::assertSame('unknown_command', $exception->errorCode);
        }
    }
}
