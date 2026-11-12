<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use PDO;
use RuntimeException;

/**
 * One poll of the event stream, with a cursor that can move past events the account
 * can't see (#281).
 *
 * The stream's cursor only advanced past delivered events, so retained events invisible
 * to the account (other rooms, or before its membership, #276) were scanned again on every
 * poll. This moves the cursor to a settled horizon S when that is safe:
 *
 *  1. S is the event sequence's last value: every id <= S was assigned before this point.
 *  2. No other session holds RowExclusiveLock on realtime_events in this database. An
 *     INSERT takes that lock before nextval and holds it until it ends, so every id <= S is
 *     committed or rolled back. (Assumes nothing calls nextval on the sequence outside an
 *     INSERT into the table; RealtimeSequenceUsageTest guards that.)
 *  3. The visible-events query runs afterwards, in a newer snapshot, so it sees every
 *     committed id <= S. If its batch isn't full, every visible event after the cursor has
 *     been returned, and the cursor may move to S.
 *
 * The check runs only when it can help (the cursor is at least SKIP_GAP ids behind S) and
 * only when the caller allows it (the stream limits it per connection), so an up-to-date,
 * idle connection never touches pg_locks.
 */
final class StreamCursor
{
    public const SKIP_GAP = 1000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{events:list<RealtimeEvent>, cursor:int, checked:bool}
     *   cursor: where the next poll starts; checked: whether the settled horizon was looked up
     */
    public function poll(AuthenticatedUser $actor, int $afterId, int $limit, bool $mayCheck): array
    {
        $horizon = null;
        $checked = false;
        if ($mayCheck) {
            $sequence = $this->sequenceValue();
            if ($sequence - $afterId >= self::SKIP_GAP) {
                $checked = true;
                if (!$this->insertInFlight()) {
                    $horizon = $sequence;
                }
            }
        }

        $events = (new EventRepository($this->pdo))->visibleAfter($actor, $afterId, $limit);
        $cursor = $afterId;
        foreach ($events as $event) {
            $cursor = max($cursor, $event->id);
        }
        if ($horizon !== null && count($events) < $limit) {
            $cursor = max($cursor, $horizon);
        }

        return ['events' => $events, 'cursor' => $cursor, 'checked' => $checked];
    }

    private function sequenceValue(): int
    {
        $statement = $this->pdo->query('SELECT last_value, is_called::int AS is_called FROM realtime_events_id_seq');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to read the realtime event sequence.');
        }
        return (int) $row['is_called'] === 1 ? (int) $row['last_value'] : 0;
    }

    private function insertInFlight(): bool
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM pg_locks l
    WHERE l.locktype = 'relation'
      AND l.relation = 'realtime_events'::regclass
      AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
      AND l.mode = 'RowExclusiveLock'
      AND l.granted
      AND l.pid <> pg_backend_pid()
)::int
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to check for in-flight realtime events.');
        }
        return (int) $statement->fetchColumn() === 1;
    }
}
