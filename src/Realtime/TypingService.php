<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * "Alex is typing…": a signal that expires within seconds and carries no
 * message text. In a room it reaches the room's members; in a direct
 * conversation only the other person, and only while the two may message.
 * It works both ways: someone who turns it off neither signals their own
 * typing nor receives anyone else's (EventRepository leaves those out).
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

    public function isShared(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT share_typing::int FROM users WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare typing preference lookup.');
        }
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function setShared(int $userId, bool $shared): bool
    {
        $statement = $this->pdo->prepare('UPDATE users SET share_typing = :shared, updated_at = NOW() WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare typing preference update.');
        }
        $statement->bindValue(':shared', $shared, PDO::PARAM_BOOL);
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return $this->isShared($userId);
    }

    /** @return bool whether a signal was published (false when one is still fresh, or typing is off) */
    public function inRoom(AuthenticatedUser $actor, int $roomId): bool
    {
        $room = (new RoomRepository($this->pdo))->findForUser($roomId, $actor->id);
        if ($room === null || !$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before typing in it.');
        }
        if (!RoomAuthorization::canPost($actor, $room)) {
            return false;
        }
        if (
            !$this->isShared($actor->id)
            || (new MuteService($this->pdo))->isMutedInRoom($actor->id, $roomId)
            || $this->recentlySignalled($actor->id, $roomId, null)
        ) {
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

    /** @return bool whether a signal was published (false when one is still fresh, or typing is off) */
    public function inConversation(AuthenticatedUser $actor, int $recipientId): bool
    {
        if ($recipientId === $actor->id) {
            throw new ApiException(400, 'validation_error', 'You cannot message yourself.');
        }
        if ($actor->guest) {
            throw new ApiException(403, 'guest_not_allowed', 'Create an account to send direct messages.');
        }
        (new DirectMessageBlockService($this->pdo))->requireMessagingAvailable($actor, $recipientId);
        if (
            !$this->isShared($actor->id)
            || (new MuteService($this->pdo))->current($actor->id, null) !== null
            || $this->recentlySignalled($actor->id, null, $recipientId)
        ) {
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
