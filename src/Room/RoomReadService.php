<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * How far each member has read in each room: unread counts for the room
 * list and the position of the "New messages" divider. Only rooms a person
 * is a member of are tracked, the rooms whose messages reach them live.
 */
final class RoomReadService
{
    /** Counts stop here; the room list shows "99+" beyond it. */
    public const UNREAD_CAP = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array{unread_count:int, last_read_message_id:int}> keyed by room ID */
    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT rr.room_id,
       rr.last_read_message_id,
       (
           SELECT COUNT(*)
           FROM (
               SELECT 1
               FROM room_messages m
               WHERE m.room_id = rr.room_id
                 AND m.id > rr.last_read_message_id
                 AND m.deleted_at IS NULL
                 AND m.sender_id IS DISTINCT FROM rr.user_id
               LIMIT :cap
           ) unread
       ) AS unread_count
FROM room_reads rr
JOIN room_members rm ON rm.room_id = rr.room_id AND rm.user_id = rr.user_id
WHERE rr.user_id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare unread counts.');
        }
        $statement->execute(['user_id' => $userId, 'cap' => self::UNREAD_CAP]);
        $reads = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $reads[(int) $row['room_id']] = [
                    'unread_count' => (int) $row['unread_count'],
                    'last_read_message_id' => (int) $row['last_read_message_id'],
                ];
            }
        }

        return $reads;
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
