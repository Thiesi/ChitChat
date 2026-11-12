<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * The largest id below which no row of a table can still commit (#286).
 *
 * Ids come from a sequence when a row is inserted, but rows become visible when their
 * transaction commits, so a lower id can appear after a higher one. The rule:
 *
 *  - A sample is (time, S): the sequence's last value S, read before the server clock time.
 *  - An INSERT takes RowExclusiveLock on the table before it draws its id, and holds it until
 *    its transaction ends. So every row that may still commit belongs to a current holder of
 *    that lock, and its id was drawn after the holder's transaction started.
 *  - If a sample was taken before the oldest current holder started, every id <= S was drawn
 *    by a transaction that has since ended: it is committed or rolled back.
 *  - A query that starts after the holder check (a newer snapshot) sees all of them.
 *
 * With no holders, the newest sample is settled (#281's rule). With holders, the newest
 * sample older than all of them is. A long holder only delays the horizon: it never moves
 * past a live holder. This assumes nothing draws ids from the sequence except INSERTs into
 * the table, which the sequence usage tests guard.
 */
final class SettledHorizon
{
    /** @var array<string, string> table => sequence; the only tables this reads */
    private const TABLES = [
        'realtime_events' => 'realtime_events_id_seq',
        'room_messages' => 'room_messages_id_seq',
    ];

    private readonly string $sequence;

    public function __construct(private readonly PDO $pdo, private readonly string $table)
    {
        $this->sequence = self::TABLES[$table] ?? throw new InvalidArgumentException('No settled horizon for ' . $table . '.');
    }

    /**
     * The sequence's last value, then the clock: the row is read before the projection, so
     * the time is no earlier than the read.
     *
     * @return array{at:int, value:int} at in microseconds since the epoch
     */
    public function sample(): array
    {
        $statement = $this->pdo->query(sprintf(
            'SELECT CASE WHEN is_called THEN last_value ELSE 0 END AS value,'
            . ' (EXTRACT(EPOCH FROM clock_timestamp()) * 1000000)::bigint AS at FROM %s',
            $this->sequence,
        ));
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to read the ' . $this->table . ' sequence.');
        }

        return ['at' => (int) $row['at'], 'value' => (int) $row['value']];
    }

    /**
     * When the oldest other session holding RowExclusiveLock on the table started its
     * transaction (microseconds since the epoch), PHP_INT_MIN when a holder's start can't be
     * read (so no sample counts as older), or null when there is no holder.
     */
    public function oldestHolderStart(): ?int
    {
        $statement = $this->pdo->query(sprintf(<<<'SQL'
SELECT COUNT(*) AS holders,
       bool_or(a.xact_start IS NULL) AS unknown,
       MIN((EXTRACT(EPOCH FROM a.xact_start) * 1000000)::bigint) AS oldest
FROM pg_locks l
LEFT JOIN pg_stat_activity a ON a.pid = l.pid
WHERE l.locktype = 'relation'
  AND l.relation = '%s'::regclass
  AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
  AND l.mode = 'RowExclusiveLock'
  AND l.granted
  AND l.pid IS DISTINCT FROM pg_backend_pid()
SQL, $this->table));
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to check for in-flight ' . $this->table . ' rows.');
        }
        if ((int) $row['holders'] === 0) {
            return null;
        }
        // A prepared transaction (no pid) or a session whose activity this role can't read.
        if ($row['unknown'] === true || $row['unknown'] === 't' || $row['unknown'] === 1 || $row['oldest'] === null) {
            return PHP_INT_MIN;
        }

        return (int) $row['oldest'];
    }

    /**
     * The settled horizon from samples taken before the holder check, or null if none is safe.
     *
     * @param list<array{at:int, value:int}> $samples
     */
    public static function choose(array $samples, ?int $oldestHolderStart): ?int
    {
        $best = null;
        foreach ($samples as $sample) {
            if ($oldestHolderStart !== null && $sample['at'] >= $oldestHolderStart) {
                continue;
            }
            $best = max($best ?? 0, $sample['value']);
        }

        return $best;
    }

    /** One-shot horizon for a single request: sample, then check holders. */
    public function now(): ?int
    {
        $sample = $this->sample();

        return self::choose([$sample], $this->oldestHolderStart());
    }
}
