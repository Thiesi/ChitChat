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
 * "Before" needs a margin of CLOCK_MARGIN, so a step of the server clock can't make a later
 * sample look earlier. With no holders, the newest sample is settled (#281's rule). Callers
 * may leave out holders they have decided to pass (SettledStreamCursor's stale rule). This
 * assumes nothing draws ids from the sequence except INSERTs into the table, which
 * RealtimeSequenceUsageTest guards.
 *
 * Reading another session's xact_start needs the same database role or pg_read_all_stats;
 * otherwise its start is unknown.
 */
class SettledHorizon
{
    /** Microseconds a sample must precede a holder's start. */
    public const CLOCK_MARGIN = 1_000_000;

    /** @var array<string, string> table => sequence; the only tables this reads */
    private const TABLES = [
        'realtime_events' => 'realtime_events_id_seq',
        'room_messages' => 'room_messages_id_seq',
    ];

    private readonly string $sequence;

    public function __construct(protected readonly PDO $pdo, protected readonly string $table)
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
     * Other sessions holding RowExclusiveLock on the table. start is when the holder's
     * transaction began (µs since the epoch), or null when it can't be read: a prepared
     * transaction (no pid), or another role without pg_read_all_stats.
     *
     * @return list<array{pid:?int, role:?string, start:?int}>
     */
    public function holders(): array
    {
        $statement = $this->pdo->query(sprintf(<<<'SQL'
SELECT l.pid,
       a.usename AS role,
       (EXTRACT(EPOCH FROM a.xact_start) * 1000000)::bigint AS start
FROM pg_locks l
LEFT JOIN pg_stat_activity a ON a.pid = l.pid
WHERE l.locktype = 'relation'
  AND l.relation = '%s'::regclass
  AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
  AND l.mode = 'RowExclusiveLock'
  AND l.granted
  AND l.pid IS DISTINCT FROM pg_backend_pid()
SQL, $this->table));
        if ($statement === false) {
            throw new RuntimeException('Unable to check for in-flight ' . $this->table . ' rows.');
        }
        $holders = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $holders[] = [
                    'pid' => $row['pid'] === null ? null : (int) $row['pid'],
                    'role' => $row['role'] === null ? null : (string) $row['role'],
                    'start' => $row['start'] === null ? null : (int) $row['start'],
                ];
            }
        }

        return $holders;
    }

    /** When the oldest committed row above $id was created (µs since the epoch), or null if none. */
    public function oldestCreatedAbove(int $id): ?int
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT (EXTRACT(EPOCH FROM created_at) * 1000000)::bigint FROM %s WHERE id > :id ORDER BY id LIMIT 1',
            $this->table,
        ));
        if ($statement === false) {
            throw new RuntimeException('Unable to read the oldest unsettled ' . $this->table . ' row.');
        }
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * The settled horizon from samples taken before the holder check, or null if none is safe.
     * $oldestHolderStart: the oldest holder that still bounds it (PHP_INT_MIN: unknown).
     *
     * @param list<array{at:int, value:int}> $samples
     */
    public static function choose(array $samples, ?int $oldestHolderStart): ?int
    {
        $best = null;
        foreach ($samples as $sample) {
            if ($oldestHolderStart !== null
                && ($oldestHolderStart === PHP_INT_MIN || $sample['at'] >= $oldestHolderStart - self::CLOCK_MARGIN)) {
                continue;
            }
            $best = max($best ?? 0, $sample['value']);
        }

        return $best;
    }

    /**
     * One-shot horizon for a single request: a sample, then the holders. With the clock margin,
     * a fresh sample settles only when nothing is being inserted.
     */
    public function now(): ?int
    {
        $sample = $this->sample();
        $oldest = null;
        foreach ($this->holders() as $holder) {
            $start = $holder['start'] ?? PHP_INT_MIN;
            $oldest = $oldest === null ? $start : min($oldest, $start);
        }

        return self::choose([$sample], $oldest);
    }
}
