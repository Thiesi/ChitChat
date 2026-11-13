<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\SettledHorizon;
use PDO;
use RuntimeException;

/**
 * How far each member has read in each room: unread counts for the room
 * list and the position of the "New messages" divider. Only rooms a person
 * is a member of are tracked, the rooms whose messages reach them live.
 * Messages from people they ignore never count.
 */
final class RoomReadService
{
    /** Counts stop here; the room list shows "99+" beyond it. */
    public const UNREAD_CAP = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every joined room: messages after the read position, and unread room mentions after it.
     *
     * A mention counts while its notification is unread, its message is after the read
     * position (0 for a membership without one) and not deleted, and its sender is not ignored now.
     * Reading the room clears the count without marking the notification read (#266).
     *
     * `unread_through_message_id` is the room's newest message when the counts were taken: a
     * client adding live messages to unread_count skips any at or below it, which this
     * snapshot already covers (#279). A message with a lower id can still commit after the
     * snapshot, though (#286), so it is kept only for clients that rely on it.
     *
     * `unread_settled_message_id`: every message id at or below it had committed when the
     * counts were taken (SettledHorizon on room_messages, checked once, before the count
     * query). `unread_counted_ids`: the ids above it that unread_count includes, in id order
     * (at most UNREAD_CAP). A client counts a live message unless its id is at or below
     * last_read_message_id or unread_settled_message_id, or is in unread_counted_ids. When an
     * older insert is still open, the position is 0 and the list holds every counted id.
     *
     * @return array<int, array{unread_count:int, last_read_message_id:?int, unread_mention_count:int, unread_through_message_id:int, unread_settled_message_id:int, unread_counted_ids:list<int>}> keyed by room ID
     */
    public function forUser(int $userId): array
    {
        // Before the count query, so it runs in a later snapshot that sees every id <= settled.
        $settled = (new SettledHorizon($this->pdo, 'room_messages'))->now() ?? 0;
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT rm.room_id,
       rr.last_read_message_id,
       (
           SELECT COUNT(*)
           FROM (
               SELECT 1
               FROM room_messages m
               WHERE m.room_id = rm.room_id
                 AND m.id > rr.last_read_message_id
                 AND m.deleted_at IS NULL
                 AND m.sender_id IS DISTINCT FROM rm.user_id
                 AND NOT EXISTS (
                     SELECT 1 FROM user_ignores ignoring
                     WHERE ignoring.user_id = rm.user_id AND ignoring.ignored_user_id = m.sender_id
                 )
               LIMIT :cap
           ) unread
       ) AS unread_count,
       (
           SELECT COUNT(*)
           FROM (
               SELECT 1
               FROM account_notifications n
               JOIN room_messages m ON m.id = (n.context_json->>'message_id')::bigint
               WHERE n.user_id = rm.user_id
                 AND n.kind = 'mentioned'
                 AND n.read_at IS NULL
                 AND n.context_json->>'message_kind' = 'room'
                 AND n.context_json->>'room_id' = rm.room_id::text
                 AND m.room_id = rm.room_id
                 AND m.id > COALESCE(rr.last_read_message_id, 0)
                 AND m.deleted_at IS NULL
                 AND NOT EXISTS (
                     SELECT 1 FROM user_ignores ignoring
                     WHERE ignoring.user_id = rm.user_id AND ignoring.ignored_user_id = m.sender_id
                 )
               LIMIT :mention_cap
           ) mentions
       ) AS unread_mention_count,
       COALESCE((SELECT MAX(newest.id) FROM room_messages newest WHERE newest.room_id = rm.room_id), 0) AS unread_through_message_id,
       -- The same conditions as unread_count, above the settled position.
       array_to_json(ARRAY(
           SELECT m.id
           FROM room_messages m
           WHERE m.room_id = rm.room_id
             AND m.id > rr.last_read_message_id
             AND m.id > :settled
             AND m.deleted_at IS NULL
             AND m.sender_id IS DISTINCT FROM rm.user_id
             AND NOT EXISTS (
                 SELECT 1 FROM user_ignores ignoring
                 WHERE ignoring.user_id = rm.user_id AND ignoring.ignored_user_id = m.sender_id
             )
           ORDER BY m.id
           LIMIT :ids_cap
       ))::text AS unread_counted_ids
FROM room_members rm
LEFT JOIN room_reads rr ON rr.room_id = rm.room_id AND rr.user_id = rm.user_id
WHERE rm.user_id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare unread counts.');
        }
        $statement->execute([
            'user_id' => $userId,
            'cap' => self::UNREAD_CAP,
            'mention_cap' => self::UNREAD_CAP,
            'settled' => $settled,
            'ids_cap' => self::UNREAD_CAP,
        ]);
        $reads = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $reads[(int) $row['room_id']] = [
                    'unread_count' => (int) $row['unread_count'],
                    'last_read_message_id' => $row['last_read_message_id'] === null ? null : (int) $row['last_read_message_id'],
                    'unread_mention_count' => (int) $row['unread_mention_count'],
                    'unread_through_message_id' => (int) $row['unread_through_message_id'],
                    'unread_settled_message_id' => $settled,
                    'unread_counted_ids' => array_values(array_map('intval', (array) json_decode((string) $row['unread_counted_ids'], true, 2, JSON_THROW_ON_ERROR))),
                ];
            }
        }

        return $reads;
    }

    /**
     * Starts a new membership's read position at the room's newest message (0 for an
     * empty room), inside the caller's membership transaction, so only later messages
     * count as unread (#268). A row left from an earlier membership only moves forward:
     * time away from the room is not unread. A message committing concurrently with the
     * join may land on either side of the snapshot, as with any position markRead sets.
     */
    public function startAt(int $userId, int $roomId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_reads (user_id, room_id, last_read_message_id, updated_at)
SELECT CAST(:user_id AS bigint), CAST(:room_id AS bigint), COALESCE(MAX(id), 0), NOW()
FROM room_messages
WHERE room_id = :message_room_id
ON CONFLICT (user_id, room_id) DO UPDATE
SET last_read_message_id = GREATEST(room_reads.last_read_message_id, EXCLUDED.last_read_message_id),
    updated_at = NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the starting read position.');
        }
        $statement->execute(['user_id' => $userId, 'room_id' => $roomId, 'message_room_id' => $roomId]);
    }

    /**
     * Moves the member's read position forward to `messageId` (never back,
     * never past the room's newest message) and returns the new position.
     */
    public function markRead(AuthenticatedUser $actor, int $roomId, int $messageId): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_reads (user_id, room_id, last_read_message_id, updated_at)
SELECT rm.user_id,
       rm.room_id,
       LEAST(CAST(:message_id AS bigint), COALESCE((SELECT MAX(id) FROM room_messages WHERE room_id = rm.room_id), 0)),
       NOW()
FROM room_members rm
JOIN rooms r ON r.id = rm.room_id AND r.deleted_at IS NULL
WHERE rm.room_id = :room_id AND rm.user_id = :user_id
ON CONFLICT (user_id, room_id) DO UPDATE
SET last_read_message_id = GREATEST(room_reads.last_read_message_id, EXCLUDED.last_read_message_id),
    updated_at = NOW()
RETURNING last_read_message_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare read position update.');
        }
        $statement->execute(['message_id' => max(0, $messageId), 'room_id' => $roomId, 'user_id' => $actor->id]);
        $position = $statement->fetchColumn();
        if ($position === false) {
            throw new ApiException(404, 'room_not_found', 'You are not a member of that room.');
        }

        return (int) $position;
    }
}
