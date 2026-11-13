<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Database;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;
use PDO;

/**
 * The room list's settled unread position and counted ids (#286): a client adding live
 * messages neither counts one the snapshot counted nor skips one that committed after it.
 */
final class RoomReadSettledTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Read-Settled-Aa1!';

    private AuthenticatedUser $owner;
    private AuthenticatedUser $member;
    private int $roomId;
    private ?PDO $publisher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $this->owner = $auth->register('Owner', self::PASSWORD, '127.0.0.1');
        $this->member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $this->roomId = $rooms->create($this->owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1')->id;
        $rooms->join($this->member, $this->roomId, '127.0.0.2');
    }

    protected function tearDown(): void
    {
        if ($this->publisher?->inTransaction()) {
            $this->publisher->rollBack();
        }
        $this->publisher = null;
        parent::tearDown();
    }

    public function testWithNothingOpenEverythingCountedIsSettledAndNoIdsAreListed(): void
    {
        $this->messages($this->pdo, 3);
        $reads = $this->reads();
        self::assertSame(3, $reads['unread_count']);
        self::assertSame($this->sequenceValue(), $reads['unread_settled_message_id']);
        self::assertSame([], $reads['unread_counted_ids']);
        self::assertSame($reads['unread_through_message_id'], $this->newestMessageId(), 'The #279 field is unchanged.');
    }

    public function testAnOpenInsertFallsBackToListingEveryCountedIdAndTheLateLowerOneStillCounts(): void
    {
        $this->publisher = Database::connect($this->config);
        $this->publisher->beginTransaction();
        [$low] = $this->messages($this->publisher, 1);
        $high = $this->messages($this->pdo, 4);

        $reads = $this->reads();
        self::assertSame(4, $reads['unread_count']);
        self::assertSame(0, $reads['unread_settled_message_id'], 'An insert older than the request\'s sample is open.');
        self::assertSame($high, $reads['unread_counted_ids'], 'Exactly the counted messages.');
        self::assertSame(max($high), $reads['unread_through_message_id']);
        self::assertGreaterThan($low, min($high));

        // The client rule: the late lower message counts once it arrives, the counted ones don't.
        self::assertTrue($this->countsLive($reads, $low), 'Committed after the snapshot, below the #279 mark.');
        foreach ($high as $id) {
            self::assertFalse($this->countsLive($reads, $id));
        }
    }

    public function testOnTheFallbackPathTheListMatchesTheCountUpToTheCap(): void
    {
        $this->publisher = Database::connect($this->config);
        $this->publisher->beginTransaction();
        $this->messages($this->publisher, 1);
        $this->messages($this->pdo, 120);

        $reads = $this->reads();
        self::assertSame(RoomReadService::UNREAD_CAP, $reads['unread_count']);
        self::assertSame(0, $reads['unread_settled_message_id']);
        self::assertCount(RoomReadService::UNREAD_CAP, $reads['unread_counted_ids'], 'As many ids as the count, at the cap.');
        $sorted = $reads['unread_counted_ids'];
        sort($sorted);
        self::assertSame($sorted, $reads['unread_counted_ids'], 'In id order.');
    }

    public function testListedIdsFollowTheCountsRules(): void
    {
        $this->publisher = Database::connect($this->config);
        $this->publisher->beginTransaction();
        $this->messages($this->publisher, 1);
        [$own] = $this->messages($this->pdo, 1, $this->member->id);
        [$deleted] = $this->messages($this->pdo, 1);
        $this->pdo->exec("UPDATE room_messages SET deleted_at = NOW() WHERE id = {$deleted}");
        $ignored = (new AuthService($this->pdo, $this->config))->register('Ignored', self::PASSWORD, '127.0.0.3');
        (new RoomService($this->pdo))->join($ignored, $this->roomId, '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_ignores (user_id, ignored_user_id) VALUES ({$this->member->id}, {$ignored->id})");
        [$fromIgnored] = $this->messages($this->pdo, 1, $ignored->id);
        $counted = $this->messages($this->pdo, 2);

        $reads = $this->reads();
        self::assertSame(2, $reads['unread_count']);
        self::assertSame($counted, $reads['unread_counted_ids']);
        self::assertNotContains($own, $reads['unread_counted_ids']);
        self::assertNotContains($fromIgnored, $reads['unread_counted_ids']);
    }

    public function testTheRoomListAndTheJoinResponseCarryTheFields(): void
    {
        $this->messages($this->pdo, 2);
        $entry = null;
        foreach ((new RoomService($this->pdo))->list($this->member) as $room) {
            if ($room['id'] === $this->roomId) {
                $entry = $room;
            }
        }
        self::assertNotNull($entry);
        self::assertSame($this->sequenceValue(), $entry['unread_settled_message_id']);
        self::assertSame([], $entry['unread_counted_ids']);
    }

    /** @return array{unread_count:int, last_read_message_id:?int, unread_mention_count:int, unread_through_message_id:int, unread_settled_message_id:int, unread_counted_ids:list<int>} */
    private function reads(): array
    {
        $reads = (new RoomReadService($this->pdo))->forUser($this->member->id)[$this->roomId] ?? null;
        self::assertNotNull($reads);

        return $reads;
    }

    /**
     * The documented client rule for a live message.
     *
     * @param array{last_read_message_id:?int, unread_settled_message_id:int, unread_counted_ids:list<int>} $reads
     */
    private function countsLive(array $reads, int $messageId): bool
    {
        return $messageId > (int) $reads['last_read_message_id']
            && $messageId > $reads['unread_settled_message_id']
            && !in_array($messageId, $reads['unread_counted_ids'], true);
    }

    /** @return list<int> */
    private function messages(PDO $connection, int $count, ?int $senderId = null): array
    {
        $sender = $senderId ?? $this->owner->id;
        $statement = $connection->query(<<<SQL
INSERT INTO room_messages (room_id, sender_id, message_type, body)
SELECT {$this->roomId}, {$sender}, 'text', 'Message ' || n FROM generate_series(1, {$count}) n
RETURNING id
SQL);
        self::assertNotFalse($statement);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);

        return $ids;
    }

    private function sequenceValue(): int
    {
        return (int) $this->pdo->query('SELECT last_value FROM room_messages_id_seq')?->fetchColumn();
    }

    private function newestMessageId(): int
    {
        return (int) $this->pdo->query("SELECT MAX(id) FROM room_messages WHERE room_id = {$this->roomId}")?->fetchColumn();
    }
}
