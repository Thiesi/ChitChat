<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;

/**
 * The event stream must never re-deliver message text that was withdrawn after it was
 * sent (#276): to a member who joins later, or to one who reconnects with an old cursor.
 */
final class RealtimeReplayPrivacyTest extends DatabaseTestCase
{
    private const PASSWORD = 'Replay-Privacy-Test-Aa1!';

    private AuthenticatedUser $moderator;
    private AuthenticatedUser $author;
    private AuthenticatedUser $member;
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $this->moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.1');
        $this->author = $auth->register('Author', self::PASSWORD, '127.0.0.2');
        $this->member = $auth->register('Member', self::PASSWORD, '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $this->roomId = $rooms->create($this->moderator, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1')->id;
        $rooms->join($this->author, $this->roomId, '127.0.0.2');
    }

    public function testAMemberWhoJoinsAfterAModeratorDeletionNeverReceivesTheOriginalText(): void
    {
        $sent = (new MessageService($this->pdo))->send($this->author, $this->roomId, 'Deleted secret');
        (new MessageService($this->pdo))->delete($this->moderator, $sent['id'], '127.0.0.1', 'Off-topic');
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');

        self::assertStringNotContainsString('Deleted secret', $this->stream($this->member, 0));
    }

    public function testAMemberWhoJoinsAfterAnEditNeverReceivesTheEarlierText(): void
    {
        $sent = (new MessageService($this->pdo))->send($this->author, $this->roomId, 'Original wording');
        (new RoomMessageMutationService($this->pdo))->edit($this->author, $sent['id'], 'Corrected wording', '127.0.0.2');
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');

        self::assertStringNotContainsString('Original wording', $this->stream($this->member, 0));
    }

    public function testAnOfflineMemberReconnectingAfterADeletionGetsTheDeletionButNotTheText(): void
    {
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');
        $cursor = $this->newestEventId();
        $sent = (new MessageService($this->pdo))->send($this->author, $this->roomId, 'Deleted while you were away');
        (new MessageService($this->pdo))->delete($this->moderator, $sent['id'], '127.0.0.1', 'Off-topic');

        $events = $this->events($this->member, $cursor);
        self::assertStringNotContainsString('Deleted while you were away', json_encode($events, JSON_THROW_ON_ERROR));
        self::assertContains('message_deleted', array_column($events, 'type'), 'The deletion itself is still delivered.');
    }

    public function testAnOfflineMemberReconnectingAfterAnEditGetsOnlyTheCurrentText(): void
    {
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');
        $cursor = $this->newestEventId();
        $sent = (new MessageService($this->pdo))->send($this->author, $this->roomId, 'First draft');
        (new RoomMessageMutationService($this->pdo))->edit($this->author, $sent['id'], 'Final text', '127.0.0.2');

        $stream = $this->stream($this->member, $cursor);
        self::assertStringNotContainsString('First draft', $stream);
        self::assertStringContainsString('Final text', $stream);
    }

    public function testAReplyQuotingALaterDeletedMessageDoesNotCarryTheQuotedText(): void
    {
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');
        $cursor = $this->newestEventId();
        $messages = new MessageService($this->pdo);
        $quoted = $messages->send($this->author, $this->roomId, 'Quoted secret');
        $messages->send($this->moderator, $this->roomId, 'Replying to that', $quoted['id']);
        $messages->delete($this->moderator, $quoted['id'], '127.0.0.1', 'Off-topic');

        $stream = $this->stream($this->member, $cursor);
        self::assertStringNotContainsString('Quoted secret', $stream);
        self::assertStringContainsString('Replying to that', $stream, 'The reply itself is still delivered.');
    }

    public function testADirectMessageWithdrawnBeforeTheRecipientReconnectsIsNotReplayed(): void
    {
        $cursor = $this->newestEventId();
        $direct = new DirectMessageService($this->pdo);
        $mutations = new DirectMessageMutationService($this->pdo);
        $deleted = $direct->send($this->author, $this->member->id, 'Private draft');
        $mutations->deleteOwn($this->author, $deleted['id'], '127.0.0.2');
        $edited = $direct->send($this->author, $this->member->id, 'Unedited words');
        $mutations->edit($this->author, $edited['id'], 'Edited words', '127.0.0.2');

        $stream = $this->stream($this->member, $cursor);
        self::assertStringNotContainsString('Private draft', $stream);
        self::assertStringNotContainsString('Unedited words', $stream);
        self::assertStringContainsString('Edited words', $stream);
    }

    public function testLiveMessagesAreDeliveredUnchanged(): void
    {
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');
        $cursor = $this->newestEventId();
        $sent = (new MessageService($this->pdo))->send($this->author, $this->roomId, 'Hello, live');

        $events = array_values(array_filter($this->events($this->member, $cursor), static fn (array $event): bool => $event['type'] === 'room_message'));
        self::assertCount(1, $events);
        $stored = json_decode((string) $this->pdo->query("SELECT payload::text FROM realtime_events WHERE event_type = 'room_message' AND id > {$cursor}")?->fetchColumn(), true);
        self::assertSame($stored, $events[0]['payload'], 'Served exactly as stored.');
        self::assertEquals($sent, $events[0]['payload']['message'], 'Which is what send() returned (jsonb only reorders keys).');
    }

    public function testOtherRoomEventsAreBoundByMembershipOnlyAndOtherwiseUnchanged(): void
    {
        $messages = new MessageService($this->pdo);
        $reactions = new \ChitChat\Reactions\RoomReactionService($this->pdo);
        $before = $messages->send($this->author, $this->roomId, 'Before the join');
        $reactions->add($this->moderator, $before['id'], '👍');
        (new RoomService($this->pdo))->join($this->member, $this->roomId, '127.0.0.3');
        self::assertSame([], array_values(array_filter(
            $this->events($this->member, 0),
            fn (array $event): bool => ($event['payload']['message']['room_id'] ?? $event['payload']['room_id'] ?? null) === $this->roomId,
        )), 'Nothing from the room before this membership.');

        $cursor = $this->newestEventId();
        $reactions->add($this->moderator, $before['id'], '🎉');
        $stored = $this->pdo->query("SELECT event_type, payload::text FROM realtime_events WHERE id > {$cursor} AND event_type <> 'room_message' ORDER BY id")?->fetchAll(\PDO::FETCH_NUM) ?: [];
        $served = array_values(array_filter($this->events($this->member, $cursor), static fn (array $event): bool => $event['type'] !== 'room_message'));
        self::assertNotSame([], $served);
        self::assertSame(
            array_map(static fn (array $row): array => ['type' => (string) $row[0], 'payload' => json_decode((string) $row[1], true)], $stored),
            $served,
            'Non-message events are served exactly as stored.',
        );
    }

    /** @return list<array{type:string, payload:array<string, mixed>}> */
    private function events(AuthenticatedUser $viewer, int $afterId): array
    {
        $events = [];
        do {
            $batch = (new EventRepository($this->pdo))->visibleAfter($viewer, $afterId, 100);
            foreach ($batch as $event) {
                $events[] = ['type' => $event->type, 'payload' => $event->payload];
                $afterId = $event->id;
            }
        } while ($batch !== []);

        return $events;
    }

    private function stream(AuthenticatedUser $viewer, int $afterId): string
    {
        return json_encode($this->events($viewer, $afterId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function newestEventId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM realtime_events')?->fetchColumn();
    }
}
