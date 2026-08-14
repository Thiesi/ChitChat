<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Reactions\DirectMessageReactionService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;

final class ReactionServiceTest extends DatabaseTestCase
{
    public function testRoomReactionAddIsIdempotentAndExposesReactorIdentity(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $message = (new MessageService($this->pdo))->send($admin, $room->id, 'React to this');
        self::assertSame([], $message['reactions']);

        $reactions = new RoomReactionService($this->pdo);
        $reactions->add($member, $message['id'], '👍');
        $result = $reactions->add($member, $message['id'], '👍');

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM room_message_reactions')->fetchColumn(),
            'A repeated add must stay idempotent under the UNIQUE constraint.',
        );
        self::assertSame(
            [[
                'emoji' => '👍',
                'users' => [['id' => $member->id, 'username' => 'Member']],
                'reacted_by_me' => true,
            ]],
            $result,
        );

        $fromAdminView = (new RoomReactionService($this->pdo))->add($admin, $message['id'], '👍');
        self::assertSame(2, count($fromAdminView[0]['users']));
        self::assertTrue($fromAdminView[0]['reacted_by_me']);

        $reactions->remove($member, $message['id'], '👍');
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM room_message_reactions')->fetchColumn(),
        );

        $reactions->remove($member, $message['id'], '👍');
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM room_message_reactions')->fetchColumn(),
            'Removing an absent reaction must be a no-op, not an error.',
        );
    }

    public function testRoomReactionRejectsInvalidEmojiAndDeletedMessages(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');

        $message = (new MessageService($this->pdo))->send($admin, $room->id, 'React to this');
        $reactions = new RoomReactionService($this->pdo);

        try {
            $reactions->add($admin, $message['id'], '🐢');
            self::fail('Expected an unsupported emoji to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_reaction_emoji', $exception->errorCode);
        }

        (new MessageService($this->pdo))->delete($admin, $message['id'], '127.0.0.1');

        try {
            $reactions->add($admin, $message['id'], '👍');
            self::fail('Expected a reaction on a deleted message to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('message_already_deleted', $exception->errorCode);
        }
    }

    public function testRoomReactionRequiresRoomAuthorization(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $outsider = $auth->register('Outsider', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $private = $rooms->create($admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $message = (new MessageService($this->pdo))->send($admin, $private->id, 'Members only');

        try {
            (new RoomReactionService($this->pdo))->add($outsider, $message['id'], '👍');
            self::fail('Expected an outsider to be denied.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testRoomHistoryAndMutationMetadataIncludeReactions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $message = (new MessageService($this->pdo))->send($admin, $room->id, 'React to this');
        (new RoomReactionService($this->pdo))->add($member, $message['id'], '🎉');

        $history = (new MessageService($this->pdo))->history($admin, $room->id);
        self::assertSame('🎉', $history[0]['reactions'][0]['emoji']);
        self::assertFalse($history[0]['reactions'][0]['reacted_by_me']);

        $viewerHistory = (new MessageService($this->pdo))->history($member, $room->id);
        self::assertTrue($viewerHistory[0]['reactions'][0]['reacted_by_me']);

        $metadata = (new RoomMessageMutationService($this->pdo))->metadata($member, $room->id, [$message['id']]);
        self::assertSame('🎉', $metadata[0]['reactions'][0]['emoji']);
        self::assertTrue($metadata[0]['reactions'][0]['reacted_by_me']);
    }

    public function testDirectMessageReactionAddIsIdempotentAndPublishesPerParticipantState(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');

        $direct = new DirectMessageService($this->pdo);
        $sent = $direct->send($alice, $bob->id, 'Hi Bob');

        $reactions = new DirectMessageReactionService($this->pdo);
        $forBob = $reactions->add($bob, $sent['id'], '❤️');
        self::assertSame(
            [['id' => $bob->id, 'username' => 'Bob']],
            $forBob[0]['users'],
        );
        self::assertTrue($forBob[0]['reacted_by_me']);

        $reactions->add($bob, $sent['id'], '❤️');
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM direct_message_reactions')->fetchColumn(),
        );

        $fromAlice = (new DirectMessageService($this->pdo))->history($alice, $bob->id);
        self::assertFalse($fromAlice[0]['reactions'][0]['reacted_by_me']);

        $metadata = (new DirectMessageMutationService($this->pdo))->metadata($alice, [$sent['id']]);
        self::assertSame('❤️', $metadata[0]['reactions'][0]['emoji']);
        self::assertFalse($metadata[0]['reactions'][0]['reacted_by_me']);
    }

    public function testDirectMessageReactionStaysAvailableAfterABlock(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');

        $direct = new DirectMessageService($this->pdo);
        $sent = $direct->send($alice, $bob->id, 'Before the block');

        (new DirectMessageBlockService($this->pdo))->block($bob, $alice->id);

        $result = (new DirectMessageReactionService($this->pdo))->add($alice, $sent['id'], '😮');
        self::assertSame('😮', $result[0]['emoji']);
    }

    public function testDirectMessageReactionRejectsNonParticipantsAndDeletedMessages(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $carol = $auth->register('Carol', 'yet another secure password', '127.0.0.3');

        $direct = new DirectMessageService($this->pdo);
        $sent = $direct->send($alice, $bob->id, 'Between Alice and Bob');
        $reactions = new DirectMessageReactionService($this->pdo);

        try {
            $reactions->add($carol, $sent['id'], '👍');
            self::fail('Expected a non-participant to be denied.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status);
            self::assertSame('message_not_found', $exception->errorCode);
        }

        (new DirectMessageMutationService($this->pdo))->deleteOwn($alice, $sent['id'], '127.0.0.1');

        try {
            $reactions->add($bob, $sent['id'], '👍');
            self::fail('Expected a reaction on a deleted direct message to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('message_already_deleted', $exception->errorCode);
        }
    }
}
