<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use Closure;
use PDO;

/**
 * The event stream's cursor for `resume=settled` connections (#286).
 *
 * Event ids are drawn at INSERT but become visible at commit, so a lower id can commit after
 * a higher one has been sent. StreamCursor (#281) moves past the last sent id and would never
 * send it. This cursor keeps two things per connection instead:
 *
 *  - settled: every visible event with an id <= settled has been sent. It is the resume
 *    position (the SSE id), and it never decreases.
 *  - delivered: the ids above settled already sent on this connection, so each is sent once.
 *
 * Each poll asks for visible events above settled that aren't in delivered, so a late lower
 * id is picked up on the next poll after its commit. settled moves to SettledHorizon's bound
 * when the poll's batch wasn't full (every visible event up to it has then been returned).
 * The horizon check runs only when the caller allows it and there is something to settle.
 *
 * A stale writer is passed: a holder whose transaction began more than $staleAfter ago, or,
 * when its start can't be read, while the oldest event above settled is that old. Otherwise
 * every reconnect (each ~25 s) would resend everything since it froze, and could starve. Its
 * events, if it ever commits, may then be missed: the documented trade-off. ChitChat's own web
 * sessions can't idle that long in a transaction (Database::WEB_IDLE_IN_TRANSACTION_TIMEOUT_MS).
 * Ages are measured on the database clock, so they hold across reconnects.
 */
final class SettledStreamCursor
{
    /** Like StreamCursor::SKIP_GAP: with nothing delivered, check only when this far behind. */
    public const SKIP_GAP = StreamCursor::SKIP_GAP;

    /** A writer older than this (µs) is passed. */
    public const STALE_AFTER = 120_000_000;

    /** A writer holding settled back for longer than this (µs) is reported. */
    public const REPORT_AFTER = 30_000_000;

    /** Samples kept for busy instances; older ones are dropped first. */
    private const MAX_SAMPLES = 32;

    private int $settled;

    /** @var array<int, true> */
    private array $delivered = [];

    /** @var list<array{at:int, value:int}> */
    private array $samples = [];

    /** The holder that kept settled from advancing at the last check, if any. */
    private ?int $blockingHolderStart = null;

    private readonly SettledHorizon $horizon;

    /** @var Closure(string, string): void */
    private readonly Closure $warn;

    /**
     * @param ?Closure(string, string): void $warn (kind, message): rate limited by the caller
     */
    public function __construct(
        private readonly PDO $pdo,
        int $resumeAfter,
        ?SettledHorizon $horizon = null,
        private readonly int $staleAfter = self::STALE_AFTER,
        ?Closure $warn = null,
    ) {
        $this->settled = max(0, $resumeAfter);
        $this->horizon = $horizon ?? new SettledHorizon($pdo, 'realtime_events');
        $this->warn = $warn ?? static function (string $kind, string $message): void {
        };
    }

    public function settled(): int
    {
        return $this->settled;
    }

    /** Whether events above settled have been sent, so the next check is worth making. */
    public function hasUnsettled(): bool
    {
        return $this->delivered !== [];
    }

    /** When the holder that last kept settled from advancing started (µs; PHP_INT_MIN: unknown). */
    public function blockingHolderStart(): ?int
    {
        return $this->blockingHolderStart;
    }

    /**
     * @return array{events:list<RealtimeEvent>, ids:list<int>, settled:int, checked:bool}
     *   events: to send, in id order; ids: the SSE id for each, a safe resume position once
     *   that event and those before it are received; settled: after this poll
     */
    public function poll(AuthenticatedUser $actor, int $limit, bool $mayCheck): array
    {
        $previous = $this->settled;
        $bound = null;
        $checked = false;
        $passed = [];
        if ($mayCheck) {
            $sample = $this->horizon->sample();
            if ($this->delivered !== [] || $sample['value'] - $this->settled >= self::SKIP_GAP) {
                $checked = true;
                $this->samples[] = $sample;
                if (count($this->samples) > self::MAX_SAMPLES) {
                    array_shift($this->samples);
                }
                [$bound, $passed] = $this->bound($sample);
            }
        }

        $events = (new EventRepository($this->pdo))->visibleAfter($actor, $this->settled, $limit, array_keys($this->delivered));
        foreach ($events as $event) {
            $this->delivered[$event->id] = true;
        }
        if ($bound !== null && count($events) < $limit && $bound > $this->settled) {
            $this->settled = $bound;
            foreach (array_keys($this->delivered) as $id) {
                if ($id <= $this->settled) {
                    unset($this->delivered[$id]);
                }
            }
            $this->samples = array_values(array_filter(
                $this->samples,
                fn (array $sample): bool => $sample['value'] > $this->settled,
            ));
            foreach ($passed as $holder) {
                ($this->warn)('realtime-settled-skip', sprintf(
                    'ChitChat realtime: passed a transaction writing realtime_events (pid %s, role %s, started %s); stream positions moved from %d to %d, and its events, if it commits, may be missed.',
                    $holder['pid'] ?? 'unknown',
                    $holder['role'] ?? 'unknown',
                    $holder['start'] === null ? 'unknown' : gmdate('Y-m-d\TH:i:s\Z', intdiv($holder['start'], 1_000_000)),
                    $previous,
                    $this->settled,
                ));
            }
        }

        // Event i may carry the new settled position only up to just below the next event in
        // the batch: if the connection drops after event i, the ones after it weren't received.
        $ids = [];
        $count = count($events);
        foreach ($events as $index => $event) {
            $ids[] = $index + 1 < $count
                ? max($previous, min($this->settled, $events[$index + 1]->id - 1))
                : $this->settled;
        }

        return ['events' => $events, 'ids' => $ids, 'settled' => $this->settled, 'checked' => $checked];
    }

    /**
     * The horizon, passing stale holders, and the holders passed.
     *
     * @param array{at:int, value:int} $sample the newest, whose time is "now" on the database clock
     * @return array{0:?int, 1:list<array{pid:?int, role:?string, start:?int}>}
     */
    private function bound(array $sample): array
    {
        $now = $sample['at'];
        $oldestBlocking = null;
        $stale = [];
        $unknown = null;
        $frozenSince = false;
        foreach ($this->horizon->holders() as $holder) {
            $start = $holder['start'];
            if ($start === null) {
                // Its age can't be read: use how long the oldest event above settled has waited.
                if ($frozenSince === false) {
                    $frozenSince = $this->horizon->oldestCreatedAbove($this->settled);
                }
                if ($frozenSince !== null && $now - $frozenSince > $this->staleAfter) {
                    $stale[] = $holder;
                    continue;
                }
                $unknown = $holder;
                $oldestBlocking = PHP_INT_MIN;
                continue;
            }
            if ($now - $start > $this->staleAfter) {
                $stale[] = $holder;
                continue;
            }
            $oldestBlocking = $oldestBlocking === null ? $start : min($oldestBlocking, $start);
        }

        $bound = SettledHorizon::choose($this->samples, $oldestBlocking);
        $this->blockingHolderStart = $oldestBlocking !== null && ($bound === null || $bound < $sample['value']) ? $oldestBlocking : null;
        if ($unknown !== null && $this->blockingHolderStart === PHP_INT_MIN) {
            ($this->warn)('realtime-settled-unknown', sprintf(
                'ChitChat realtime: a transaction writing realtime_events (pid %s, role %s) holds settled stream positions back, and its start can\'t be read (give the ChitChat database role pg_read_all_stats, or use the same role).',
                $unknown['pid'] ?? 'unknown',
                $unknown['role'] ?? 'unknown',
            ));
        } elseif ($this->blockingHolderStart !== null && $now - $this->blockingHolderStart > self::REPORT_AFTER) {
            ($this->warn)('realtime-settled-blocked', sprintf(
                'ChitChat realtime: a transaction writing realtime_events has been open for %d s; settled stream positions wait for it, and pass it after %d s.',
                intdiv($now - $this->blockingHolderStart, 1_000_000),
                intdiv($this->staleAfter, 1_000_000),
            ));
        }

        return [$bound, $stale];
    }
}
