<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
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
 */
final class SettledStreamCursor
{
    /** Like StreamCursor::SKIP_GAP: with nothing delivered, check only when this far behind. */
    public const SKIP_GAP = StreamCursor::SKIP_GAP;

    /** Samples kept for busy instances; older ones are dropped first. */
    private const MAX_SAMPLES = 32;

    private int $settled;

    /** @var array<int, true> */
    private array $delivered = [];

    /** @var list<array{at:int, value:int}> */
    private array $samples = [];

    /** Start time (µs) of a holder that blocked the last check, if any. */
    private ?int $blockingHolderStart = null;

    private readonly SettledHorizon $horizon;

    public function __construct(private readonly PDO $pdo, int $resumeAfter)
    {
        $this->settled = max(0, $resumeAfter);
        $this->horizon = new SettledHorizon($pdo, 'realtime_events');
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

    /** When the holder that last kept settled from advancing started (µs since the epoch). */
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
        if ($mayCheck) {
            $sample = $this->horizon->sample();
            if ($this->delivered !== [] || $sample['value'] - $this->settled >= self::SKIP_GAP) {
                $checked = true;
                $this->samples[] = $sample;
                if (count($this->samples) > self::MAX_SAMPLES) {
                    array_shift($this->samples);
                }
                $oldest = $this->horizon->oldestHolderStart();
                $bound = SettledHorizon::choose($this->samples, $oldest);
                $this->blockingHolderStart = $oldest !== null && ($bound === null || $bound < $sample['value']) ? $oldest : null;
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
}
