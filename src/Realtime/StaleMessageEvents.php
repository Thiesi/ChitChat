<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Room\MessageService;
use PDO;
use RuntimeException;

/**
 * Brings replayed message events up to date before they are served (#276).
 *
 * A room_message or direct_message event stores the message as it was when the event
 * was published. Retained events can be served much later: to a client reconnecting
 * with an old cursor, or (for global moderators) after a join. If the message, or the
 * message it quotes, was deleted or edited after the event was published (or no longer
 * exists), the stored
 * payload would hand out text that was since withdrawn. Such events are re-rendered
 * from the message's current state for the viewer; a message that no longer exists is
 * redacted. Events are never dropped, so the stream's cursor still moves past them.
 *
 * Live events (nothing changed since they were published) pass through unchanged.
 */
final class StaleMessageEvents
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<RealtimeEvent> $events
     * @return list<RealtimeEvent>
     */
    public function refresh(int $viewerUserId, array $events): array
    {
        $roomEvents = [];
        $directEvents = [];
        foreach ($events as $event) {
            if ($event->type === 'room_message') {
                $roomEvents[] = $event->id;
            } elseif ($event->type === 'direct_message') {
                $directEvents[] = $event->id;
            }
        }
        $staleRoom = $roomEvents === [] ? [] : $this->stale($roomEvents, 'room_messages');
        $staleDirect = $directEvents === [] ? [] : $this->stale($directEvents, 'direct_messages');
        if ($staleRoom === [] && $staleDirect === []) {
            return $events;
        }

        $messages = new MessageService($this->pdo);
        $direct = new DirectMessageService($this->pdo);
        $refreshed = [];
        foreach ($events as $event) {
            $messageId = $this->messageId($event);
            if (isset($staleRoom[$event->id]) && $messageId !== null) {
                $current = $staleRoom[$event->id] ? $messages->findStoredMessage($messageId, $viewerUserId) : null;
                $event = $this->withMessage($event, $current ?? $this->redacted($event));
            } elseif (isset($staleDirect[$event->id]) && $messageId !== null) {
                $current = $staleDirect[$event->id] ? $direct->currentEventMessage($messageId, $viewerUserId) : null;
                $event = $this->withMessage($event, $current ?? $this->redacted($event));
            }
            $refreshed[] = $event;
        }

        return $refreshed;
    }

    /**
     * Event IDs whose message, or quoted message, changed after the event was published.
     * The value is whether the message still exists (false: purged, so redact).
     *
     * @param list<int> $eventIds
     * @return array<int, bool>
     */
    private function stale(array $eventIds, string $table): array
    {
        $statement = $this->pdo->prepare(<<<SQL
SELECT e.id, (m.id IS NOT NULL) AS present
FROM realtime_events e
LEFT JOIN {$table} m ON m.id = (e.payload->'message'->>'id')::bigint
LEFT JOIN {$table} quoted ON quoted.id = m.reply_to_message_id
WHERE e.id = ANY(CAST(:ids AS bigint[]))
  AND (
      m.id IS NULL
      OR (m.reply_to_message_id IS NOT NULL AND quoted.id IS NULL)
      OR GREATEST(m.deleted_at, m.edited_at, quoted.deleted_at, quoted.edited_at) > e.created_at
  )
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the replayed-message check.');
        }
        $statement->execute(['ids' => '{' . implode(',', $eventIds) . '}']);
        $stale = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $present = $row['present'];
                $stale[(int) $row['id']] = $present === true || $present === 1 || $present === 't' || $present === '1';
            }
        }

        return $stale;
    }

    private function messageId(RealtimeEvent $event): ?int
    {
        $message = $event->payload['message'] ?? null;
        $id = is_array($message) ? ($message['id'] ?? null) : null;

        return is_int($id) ? $id : null;
    }

    /** @return array<string, mixed> */
    private function redacted(RealtimeEvent $event): array
    {
        /** @var array<string, mixed> $message */
        $message = is_array($event->payload['message'] ?? null) ? $event->payload['message'] : [];
        foreach (['body', 'attachment', 'reply_to'] as $field) {
            if (array_key_exists($field, $message)) {
                $message[$field] = null;
            }
        }
        if (array_key_exists('mentions', $message)) {
            $message['mentions'] = [];
        }
        $message['deleted'] = true;

        return $message;
    }

    /** @param array<string, mixed> $message */
    private function withMessage(RealtimeEvent $event, array $message): RealtimeEvent
    {
        return new RealtimeEvent(
            id: $event->id,
            type: $event->type,
            roomId: $event->roomId,
            targetUserId: $event->targetUserId,
            actorUserId: $event->actorUserId,
            payload: ['message' => $message] + $event->payload,
            createdAt: $event->createdAt,
        );
    }
}
