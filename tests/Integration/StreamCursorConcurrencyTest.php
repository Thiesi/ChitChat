<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Database;
use ChitChat\Realtime\RealtimeEvent;
use ChitChat\Realtime\SettledStreamCursor;
use ChitChat\Realtime\StreamCursor;
use ChitChat\Room\RoomService;
use PDO;

/**
 * Event ids commit out of order; a settled-resume connection still sends every visible event
 * exactly once and never resumes past an unsent one (#286). Publishers are separate database
 * sessions whose transactions the test holds open and commits in a chosen order.
 */
final class StreamCursorConcurrencyTest extends DatabaseTestCase
{
    private const PASSWORD = 'Stream-Settled-Race-Aa1!';

    private AuthenticatedUser $member;
    private int $roomId;

    /** @var list<PDO> */
    private array $publishers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', self::PASSWORD, '127.0.0.1');
        $this->member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $this->roomId = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1')->id;
        $rooms->join($this->member, $this->roomId, '127.0.0.2');
    }

    protected function tearDown(): void
    {
        foreach ($this->publishers as $publisher) {
            if ($publisher->inTransaction()) {
                $publisher->rollBack();
            }
        }
        $this->publishers = [];
        parent::tearDown();
    }

    public function testALowerIdCommittingAfterAHigherOneIsSentExactlyOnce(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        $start = $cursor->settled();
        $late = $this->openPublisher();
        $lowId = $this->publish($late);
        $highId = $this->publish($this->pdo);
        self::assertGreaterThan($lowId, $highId);

        $first = $cursor->poll($this->member, 100, true);
        self::assertSame([$highId], $this->ids($first['events']), 'The committed higher id is sent at once.');
        self::assertLessThan($lowId, $first['settled'], 'Settled stays below the open lower id.');
        self::assertSame([$start], $first['ids'], 'Its SSE id is the resume position, not its own id.');

        $late->commit();
        $second = $cursor->poll($this->member, 100, true);
        self::assertSame([$lowId], $this->ids($second['events']), 'The late lower id arrives on the next poll.');
        self::assertGreaterThanOrEqual($highId, $second['settled'], 'Nothing is open: settled passes both.');

        $third = $cursor->poll($this->member, 100, true);
        self::assertSame([], $third['events'], 'Neither is sent again.');
    }

    public function testWithoutTheOptInTheStreamBehavesAsBefore(): void
    {
        // The default cursor (#281) is unchanged: it resumes after the last sent id, so this
        // is the gap resume=settled closes. Clients that don't opt in see exactly this.
        $start = $this->newestEventId();
        $late = $this->openPublisher();
        $lowId = $this->publish($late);
        $highId = $this->publish($this->pdo);
        $first = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, true);
        self::assertSame([$highId], $this->ids($first['events']));
        self::assertSame($highId, $first['cursor']);
        $late->commit();
        $second = (new StreamCursor($this->pdo))->poll($this->member, $first['cursor'], 100, true);
        self::assertNotContains($lowId, $this->ids($second['events']));
    }

    public function testSettledNeverDecreasesWhilePublishersComeAndGo(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        $positions = [$cursor->settled()];
        $sent = [];
        $open = [];
        for ($round = 0; $round < 12; $round++) {
            if ($round % 3 === 0) {
                $publisher = $this->openPublisher();
                $this->publish($publisher);
                $open[] = $publisher;
            }
            $this->publish($this->pdo);
            if ($round % 4 === 3 && $open !== []) {
                array_shift($open)->commit();
            }
            $poll = $cursor->poll($this->member, 100, true);
            $positions[] = $poll['settled'];
            foreach ($poll['ids'] as $id) {
                $positions[] = $id;
            }
            array_push($sent, ...$this->ids($poll['events']));
        }
        foreach ($open as $publisher) {
            $publisher->commit();
        }
        $final = $cursor->poll($this->member, 100, true);
        array_push($sent, ...$this->ids($final['events']));

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Every resume position is at least the one before.');
        self::assertSame(array_values(array_unique($sent)), $sent, 'No event is sent twice.');
        self::assertSame($this->visibleIds(), $this->sortedCopy($sent), 'Every visible event is sent.');
        self::assertGreaterThanOrEqual(max($sent), $final['settled']);
    }

    public function testAnOlderSampleSettlesPastCommittedEventsWhileANewerPublisherIsOpen(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        // Something sent and unsettled, so the next polls check.
        $this->publish($this->pdo);
        $cursor->poll($this->member, 100, true);
        $first = $this->openPublisher();
        $firstId = $this->publish($first);
        $this->publish($this->pdo);
        // A check while the first publisher is open records a sample it can't use yet.
        $sampled = $this->sequenceValue();
        $blocked = $cursor->poll($this->member, 100, true);
        self::assertLessThan($firstId, $blocked['settled']);

        $first->commit();
        $second = $this->openPublisher();
        $secondId = $this->publish($second);
        $poll = $cursor->poll($this->member, 100, true);
        self::assertSame($sampled, $poll['settled'], 'The sample taken before the newer publisher began is settled.');
        self::assertContains($firstId, $this->ids($poll['events']));
        self::assertGreaterThan($poll['settled'], $secondId);

        $second->commit();
        $done = $cursor->poll($this->member, 100, true);
        self::assertSame([$secondId], $this->ids($done['events']));
        // Sent and unsettled now, so the next poll checks and settles past it.
        self::assertGreaterThanOrEqual($secondId, $cursor->poll($this->member, 100, true)['settled']);
    }

    public function testALongPublisherOnlyDelaysSettlingAndSentEventsDontCountAgainstTheLimit(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        $long = $this->openPublisher();
        $longId = $this->publish($long);
        $this->pdo->exec("INSERT INTO realtime_events (event_type, room_id, payload) SELECT 'room_broadcast', {$this->roomId}, jsonb_build_object('n', n) FROM generate_series(1, 150) n");

        $first = $cursor->poll($this->member, 100, true);
        $second = $cursor->poll($this->member, 100, true);
        $third = $cursor->poll($this->member, 100, true);
        self::assertCount(100, $first['events']);
        self::assertCount(50, $second['events'], 'Already-sent events are excluded before the limit.');
        self::assertSame([], $third['events']);
        foreach ([$first, $second, $third] as $poll) {
            self::assertLessThan($longId, $poll['settled'], 'Never past the open publisher.');
        }
        self::assertNotNull($cursor->blockingHolderStart(), 'The open publisher is reported, for the stuck-holder warning.');

        $long->commit();
        $after = $cursor->poll($this->member, 100, true);
        self::assertSame([$longId], $this->ids($after['events']));
        self::assertGreaterThan($longId, $after['settled']);
        self::assertNull($cursor->blockingHolderStart());
    }

    public function testEachEventInABatchCarriesAResumePositionBelowTheNextOne(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        $start = $cursor->settled();
        // One event sent and unsettled, so the next poll checks and settles.
        $this->publish($this->pdo);
        self::assertSame([$start], $cursor->poll($this->member, 100, true)['ids']);
        $ids = [$this->publish($this->pdo), $this->publish($this->pdo), $this->publish($this->pdo)];

        $poll = $cursor->poll($this->member, 100, true);
        self::assertSame($ids, $this->ids($poll['events']));
        self::assertGreaterThanOrEqual($ids[2], $poll['settled']);
        // If the connection drops after the first event, resuming must still send the second.
        self::assertSame([max($start, $ids[1] - 1), max($start, $ids[2] - 1), $poll['settled']], $poll['ids']);
    }

    public function testAnIdleUpToDateConnectionNeverChecksTheLockTable(): void
    {
        $cursor = new SettledStreamCursor($this->pdo, $this->newestEventId());
        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($cursor->poll($this->member, 100, true)['checked']);
        }
        self::assertFalse($cursor->hasUnsettled());
    }

    private function openPublisher(): PDO
    {
        $publisher = Database::connect($this->config);
        $publisher->beginTransaction();
        $this->publishers[] = $publisher;

        return $publisher;
    }

    private function publish(PDO $connection): int
    {
        $statement = $connection->query("INSERT INTO realtime_events (event_type, room_id, payload) VALUES ('room_broadcast', {$this->roomId}, '{}'::jsonb) RETURNING id");
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<RealtimeEvent> $events
     * @return list<int>
     */
    private function ids(array $events): array
    {
        return array_map(static fn (RealtimeEvent $event): int => $event->id, $events);
    }

    /** @return list<int> */
    private function visibleIds(): array
    {
        $statement = $this->pdo->query("SELECT id FROM realtime_events WHERE room_id = {$this->roomId} AND event_type = 'room_broadcast' ORDER BY id");
        self::assertNotFalse($statement);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sortedCopy(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function newestEventId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM realtime_events')?->fetchColumn();
    }

    private function sequenceValue(): int
    {
        return (int) $this->pdo->query('SELECT last_value FROM realtime_events_id_seq')?->fetchColumn();
    }
}
