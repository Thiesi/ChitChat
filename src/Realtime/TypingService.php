<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * "Alex is typing…": a signal that expires within seconds and carries no
 * message text. In a room it reaches the room's members; in a direct
 * conversation only the other person, and only while the two may message.
 */
final class TypingService
{
    /** Each signal stops showing after this; the browser renews it while typing. */
    public const LIFETIME_SECONDS = 8;
    /** Signals closer together than this are dropped, to keep the event table small. */
    private const MIN_INTERVAL_SECONDS = 3;

    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->events = new EventRepository($pdo);
    }

    /** @return bool whether a signal was published (false when one is still fresh) */
    public function inRoom(AuthenticatedUser $actor, int $roomId): bool
    {
        $room = (new RoomRepository($this->pdo))->findForUser($roomId, $actor->id);
        if ($room === null || !$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before typing in it.');
        }
        if ($this->recentlySignalled($actor->id, $roomId, null)) {
            return false;
        }
        $this->events->publish(
            type: 'typing',
            payload: ['room_id' => $roomId, 'user' => ['id' => $actor->id, 'username' => $actor->username]],
            roomId: $roomId,
            actorUserId: $actor->id,
            expiresAt: new DateTimeImmutable('+' . self::LIFETIME_SECONDS . ' seconds'),
        );

        return true;
    }

    /** @return bool whether a signal was published (false when one is still fresh) */
    public function inConversation(AuthenticatedUser $actor, int $recipientId): bool
    {
        if ($recipientId === $actor->id) {
            throw new ApiException(400, 'validation_error', 'You cannot message yourself.');
        }
        (new DirectMessageBlockService($this->pdo))->requireMessagingAvailable($actor, $recipientId);
        if ($this->recentlySignalled($actor->id, null, $recipientId)) {
            return false;
        }
        $this->events->publish(
            type: 'typing',
            payload: ['room_id' => null, 'user' => ['id' => $actor->id, 'username' => $actor->username]],
            targetUserId: $recipientId,
            actorUserId: $actor->id,
            expiresAt: new DateTimeImmutable('+' . self::LIFETIME_SECONDS . ' seconds'),
        );

        return true;
    }

    private function recentlySignalled(int $actorId, ?int $roomId, ?int $targetId): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1
FROM realtime_events
WHERE event_type = 'typing'
  AND actor_user_id = :actor
  AND room_id IS NOT DISTINCT FROM CAST(:room_id AS bigint)
  AND target_user_id IS NOT DISTINCT FROM CAST(:target_id AS bigint)
  AND created_at > NOW() - make_interval(secs => :interval)
LIMIT 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare typing lookup.');
        }
        $statement->execute([
            'actor' => $actorId,
            'room_id' => $roomId,
            'target_id' => $targetId,
            'interval' => self::MIN_INTERVAL_SECONDS,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
