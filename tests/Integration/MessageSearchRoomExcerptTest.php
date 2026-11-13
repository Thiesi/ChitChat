<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Search\MessageSearchService;
use ChitChat\Search\SearchExcerpt;
use DateTimeImmutable;

/** Message search: the room filter and the match-centred excerpt (#283). */
final class MessageSearchRoomExcerptTest extends DatabaseTestCase
{
    private AuthService $auth;
    private MessageSearchService $search;
    private AuthenticatedUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->pdo, $this->config);
        $this->search = new MessageSearchService($this->pdo);
        $this->admin = $this->auth->register('Admin', 'a very secure password', '127.0.0.1');
    }

    public function testRoomIdLimitsResultsToThatRoom(): void
    {
        $actor = $this->auth->register('Searcher', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $one = $rooms->create($this->admin, 'one', 'One', '', 'public', 0, 0, '127.0.0.1');
        $two = $rooms->create($this->admin, 'two', 'Two', '', 'public', 0, 0, '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $messages->send($this->admin, $one->id, 'Needle in room one');
        $messages->send($this->admin, $two->id, 'Needle in room two');
        (new DirectMessageService($this->pdo))->send($this->admin, $actor->id, 'Needle in a direct message');

        $result = $this->search->search($actor, 'needle', 'all', 25, 0, $one->id);
        self::assertSame(['Needle in room one'], array_column($result['results'], 'excerpt'));
        self::assertSame(['Needle in room one'], array_column($this->search->search($actor, 'needle', 'rooms', 25, 0, $one->id)['results'], 'excerpt'));
        self::assertCount(3, $this->search->search($actor, 'needle')['results'], 'Without room_id, unchanged.');

        $this->assertRefused(400, 'validation_error', fn () => $this->search->search($actor, 'needle', 'direct', 25, 0, $one->id));
        $this->assertRefused(400, 'validation_error', fn () => $this->search->search($actor, 'needle', 'all', 25, 0, 0));
    }

    public function testARoomTheCallerCantSearchAnswersExactlyLikeAMissingRoom(): void
    {
        $actor = $this->auth->register('Searcher', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $private = $rooms->create($this->admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $unlisted = $rooms->create($this->admin, 'unlisted', 'Unlisted', '', 'unlisted', 0, 0, '127.0.0.1');
        $older = $rooms->create($this->admin, 'older', 'Older', '', 'public', 21, 0, '127.0.0.1');
        $deleted = $rooms->create($this->admin, 'gone', 'Gone', '', 'public', 0, 0, '127.0.0.1');
        $this->pdo->exec("UPDATE rooms SET deleted_at = NOW() WHERE id = {$deleted->id}");

        foreach ([$private->id, $unlisted->id, $older->id, $deleted->id, 999_999] as $roomId) {
            try {
                $this->search->search($actor, 'needle', 'all', 25, 0, $roomId);
                self::fail("Room {$roomId} was searchable.");
            } catch (ApiException $exception) {
                self::assertSame([404, 'room_not_found', 'Room not found.'], [$exception->status, $exception->errorCode, $exception->getMessage()]);
            }
        }

        // Invited to the unlisted room, or a member of the private one: searchable.
        $rooms->invite($this->admin, $unlisted->id, $actor->id, '127.0.0.1');
        $rooms->invite($this->admin, $private->id, $actor->id, '127.0.0.1');
        $rooms->join($actor, $private->id, '127.0.0.2');
        self::assertSame([], $this->search->search($actor, 'needle', 'all', 25, 0, $unlisted->id)['results']);
        self::assertSame([], $this->search->search($actor, 'needle', 'all', 25, 0, $private->id)['results']);

        // An adult may search the age-restricted room; a global moderator any room.
        $adult = $this->auth->register('Adult', 'a grown up passphrase', '127.0.0.3', (new DateTimeImmutable('-30 years'))->format('Y-m-d'));
        self::assertSame([], $this->search->search($adult, 'needle', 'all', 25, 0, $older->id)['results']);
        $moderator = $this->auth->register('Moderator', 'a steady watchful passphrase', '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $moderator = (new UserRepository($this->pdo))->findAuthenticatedById($moderator->id);
        self::assertNotNull($moderator);
        self::assertSame([], $this->search->search($moderator, 'needle', 'all', 25, 0, $private->id)['results']);
    }

    public function testALongMessageGetsAnExcerptAroundItsMatchWithOffsets(): void
    {
        $body = str_repeat('alpha beta gamma delta ', 30) . 'the deployment rehearsal 🎉 went well ' . str_repeat('omega psi chi phi ', 30);
        $result = $this->only($this->roomMessage($body), 'deployment');

        self::assertStringStartsWith('… ', $result['excerpt']);
        self::assertStringEndsWith(' …', $result['excerpt']);
        self::assertLessThanOrEqual(SearchExcerpt::MAX, mb_strlen($result['excerpt']));
        self::assertSame(['deployment'], $this->marked($result));
    }

    public function testPhrasesMarkTheirWordsAndNegatedWordsAreNeverMarked(): void
    {
        $this->roomMessage('The release went out. Later the Release notes were fixed.');
        // PostgreSQL 16 marks every occurrence of a phrase's words, not only the phrase.
        self::assertSame(['release', 'Release', 'notes'], $this->marked($this->only(null, '"release notes"')));

        // "apple OR -banana" also matches any message without banana: start from an empty room.
        $this->pdo->exec('DELETE FROM room_messages');
        $this->roomMessage('apple banana cherry');
        // "apple OR -banana" matches; ts_headline would also mark banana, which is negated.
        self::assertSame(['apple'], $this->marked($this->only(null, 'apple OR -banana')));
        self::assertSame(['cherry'], $this->marked($this->only(null, 'cherry -durian')));
    }

    public function testSentinelsInAMessageCantForgeAMatch(): void
    {
        $this->roomMessage('a ' . SearchExcerpt::START . 'forged' . SearchExcerpt::STOP . ' mark next to the needle');
        $result = $this->only(null, 'needle');
        self::assertSame('a forged mark next to the needle', $result['excerpt']);
        self::assertSame(['needle'], $this->marked($result));
    }

    public function testDirectMessagesGetOffsetsToo(): void
    {
        $peer = $this->auth->register('Peer', 'a quiet harbour password', '127.0.0.5');
        (new DirectMessageService($this->pdo))->send($this->admin, $peer->id, 'Meet at the Harbour, harbour gate 3');
        $result = $this->search->search($peer, 'harbour', 'direct')['results'][0];
        self::assertSame(['Harbour', 'harbour'], $this->marked($result));
    }

    private ?int $roomId = null;

    private function roomMessage(string $body): string
    {
        $this->roomId ??= (new RoomService($this->pdo))->create($this->admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1')->id;
        $this->pdo->prepare("INSERT INTO room_messages (room_id, sender_id, message_type, body) VALUES (:room, :sender, 'text', :body)")
            ->execute(['room' => $this->roomId, 'sender' => $this->admin->id, 'body' => $body]);

        return $body;
    }

    /** @return array<string, mixed> */
    private function only(?string $unused, string $query): array
    {
        $results = $this->search->search($this->admin, $query, 'rooms')['results'];
        self::assertCount(1, $results, $query);

        return $results[0];
    }

    /**
     * The marked words, read back through the UTF-16 offsets as a client would.
     *
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function marked(array $result): array
    {
        $utf16 = mb_convert_encoding((string) $result['excerpt'], 'UTF-16LE', 'UTF-8');
        $words = [];
        foreach ($result['excerpt_matches'] as $match) {
            $words[] = mb_convert_encoding(substr($utf16, $match['start'] * 2, $match['length'] * 2), 'UTF-8', 'UTF-16LE');
        }

        return $words;
    }

    private function assertRefused(int $status, string $code, callable $action): void
    {
        try {
            $action();
            self::fail("Expected {$status} {$code}.");
        } catch (ApiException $exception) {
            self::assertSame([$status, $code], [$exception->status, $exception->errorCode]);
        }
    }
}
