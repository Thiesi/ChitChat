<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Search\MessageSearchService;
use DateTimeImmutable;

final class MessageSearchServiceTest extends DatabaseTestCase
{
    public function testSearchReturnsOnlyCurrentMessagesVisibleToTheParticipant(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $adultBirthDate = (new DateTimeImmutable('today'))->modify('-20 years')->format('Y-m-d');
        $actor = $auth->register('Searcher', 'another secure password', '127.0.0.2', $adultBirthDate);
        $peer = $auth->register('Peer', 'different secure password', '127.0.0.3');
        $outsider = $auth->register('Outsider', 'yet another secure password', '127.0.0.4');

        $rooms = new RoomService($this->pdo);
        $messages = new MessageService($this->pdo);
        $public = $rooms->create($admin, 'public', 'Public', '', 'public', 0, 0, '127.0.0.1');
        $private = $rooms->create($admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $hiddenPrivate = $rooms->create($admin, 'hidden', 'Hidden', '', 'private', 0, 0, '127.0.0.1');
        $unlisted = $rooms->create($admin, 'unlisted', 'Unlisted', '', 'unlisted', 0, 0, '127.0.0.1');
        $ageRestricted = $rooms->create($admin, 'older', 'Older', '', 'public', 21, 0, '127.0.0.1');
        $rooms->invite($admin, $private->id, $actor->id, '127.0.0.1');
        $rooms->join($actor, $private->id, '127.0.0.2');

        $messages->send($admin, $public->id, 'Needle in a public room');
        $messages->send($admin, $private->id, 'Needle in a joined private room');
        $messages->send($admin, $hiddenPrivate->id, 'Needle in a hidden private room');
        $messages->send($admin, $unlisted->id, 'Needle in an undiscoverable unlisted room');
        $messages->send($admin, $ageRestricted->id, 'Needle in an age restricted room');
        $deletedRoomMessage = $messages->send($admin, $public->id, 'Needle deleted from a room');
        $messages->delete($admin, $deletedRoomMessage['id'], '127.0.0.1');
        $editedRoomMessage = $messages->send($admin, $public->id, 'Legacyphrase before editing');
        $this->pdo->prepare(
            'UPDATE room_messages SET body = :body, edited_at = NOW(), edited_by = :actor WHERE id = :id',
        )->execute([
            'body' => 'Needle in the current edited body',
            'actor' => $admin->id,
            'id' => $editedRoomMessage['id'],
        ]);

        $directMessages = new DirectMessageService($this->pdo);
        $directMessages->send($actor, $peer->id, 'Needle in my direct conversation');
        $directMessages->send($peer, $outsider->id, 'Needle in somebody else’s direct conversation');
        $deletedDirectMessage = $directMessages->send($peer, $actor->id, 'Needle deleted from a direct conversation');
        $this->pdo->prepare(
            'UPDATE direct_messages SET deleted_at = NOW(), deleted_by = :actor WHERE id = :id',
        )->execute([
            'actor' => $peer->id,
            'id' => $deletedDirectMessage['id'],
        ]);

        $result = (new MessageSearchService($this->pdo))->search($actor, 'needle');
        $excerpts = array_column($result['results'], 'excerpt');

        self::assertContains('Needle in a public room', $excerpts);
        self::assertContains('Needle in a joined private room', $excerpts);
        self::assertContains('Needle in the current edited body', $excerpts);
        self::assertContains('Needle in my direct conversation', $excerpts);
        self::assertNotContains('Needle in a hidden private room', $excerpts);
        self::assertNotContains('Needle in an undiscoverable unlisted room', $excerpts);
        self::assertNotContains('Needle in an age restricted room', $excerpts);
        self::assertNotContains('Needle deleted from a room', $excerpts);
        self::assertNotContains('Needle deleted from a direct conversation', $excerpts);
        self::assertNotContains('Needle in somebody else’s direct conversation', $excerpts);

        $legacyResult = (new MessageSearchService($this->pdo))->search($actor, 'legacyphrase');
        self::assertSame([], $legacyResult['results']);
    }

    public function testSearchSupportsScopesPaginationAndBoundedValidation(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $actor = $auth->register('Searcher', 'another secure password', '127.0.0.2');
        $peer = $auth->register('Peer', 'different secure password', '127.0.0.3');
        $room = (new RoomService($this->pdo))->create(
            $admin,
            'general',
            'General',
            '',
            'public',
            0,
            0,
            '127.0.0.1',
        );
        (new MessageService($this->pdo))->send($admin, $room->id, 'Searchtoken room result');
        (new DirectMessageService($this->pdo))->send($actor, $peer->id, 'Searchtoken direct result');
        $service = new MessageSearchService($this->pdo);

        $firstPage = $service->search($actor, 'searchtoken', 'all', 1, 0);
        self::assertCount(1, $firstPage['results']);
        self::assertTrue($firstPage['has_more']);
        self::assertSame(1, $firstPage['next_offset']);

        $secondPage = $service->search($actor, 'searchtoken', 'all', 1, 1);
        self::assertCount(1, $secondPage['results']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_offset']);

        self::assertSame(
            ['room'],
            array_values(array_unique(array_column($service->search($actor, 'searchtoken', 'rooms')['results'], 'kind'))),
        );
        self::assertSame(
            ['direct'],
            array_values(array_unique(array_column($service->search($actor, 'searchtoken', 'direct')['results'], 'kind'))),
        );

        foreach ([['x', 'all'], ['searchtoken', 'unsupported']] as [$query, $scope]) {
            try {
                $service->search($actor, $query, $scope);
                self::fail('Expected bounded search validation failure.');
            } catch (ApiException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
            }
        }
    }
}
