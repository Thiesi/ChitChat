<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Username;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class PingService
{
    private readonly RoomRepository $rooms;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    public function send(
        AuthenticatedUser $actor,
        int $roomId,
        string $targetUsername,
        string $messageInput = '',
    ): RealtimeEvent {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before sending pings.');
        }

        $canonical = Username::canonical($targetUsername);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.username
FROM users u
JOIN room_members rm ON rm.user_id = u.id
WHERE u.username_canonical = :username
  AND u.account_state = 'active'
  AND rm.room_id = :room_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ping target lookup.');
        }
        $statement->execute(['username' => $canonical, 'room_id' => $roomId]);
        $target = $statement->fetch();
        if (!is_array($target)) {
            throw new ApiException(404, 'ping_target_not_found', 'That active user is not a member of this room.');
        }
        $targetUserId = (int) $target['id'];
        if ($targetUserId === $actor->id) {
            throw new ApiException(400, 'cannot_ping_self', 'You cannot ping yourself.');
        }

        $message = trim($messageInput);
        if (mb_strlen($message, 'UTF-8') > 500) {
            throw new ApiException(400, 'ping_too_long', 'Ping text must not exceed 500 characters.');
        }

        return $this->events->publish(
            type: 'ping',
            payload: [
                'room_id' => $roomId,
                'sender' => $actor->toArray(),
                'target' => [
                    'id' => $targetUserId,
                    'username' => (string) $target['username'],
                ],
                'message' => $message,
            ],
            roomId: $roomId,
            targetUserId: $targetUserId,
            actorUserId: $actor->id,
            expiresAt: new DateTimeImmutable('+1 day'),
        );
    }
}
