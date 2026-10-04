<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Username;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * /ping: a durable, private nudge. Each ping is stored once, shown as a
 * system message only to its sender and target in its room, and reaches an
 * offline target through the notification list (and push, when enabled).
 */
final class PingService
{
    public const HISTORY_LIMIT = 100;

    private readonly RoomRepository $rooms;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    /**
     * @return array{id:int, room_id:int, sender:array{id:int, username:string}, target:array{id:int, username:string}, message:string, created_at:string}
     */
    public function send(
        AuthenticatedUser $actor,
        int $roomId,
        string $targetUsername,
        string $messageInput = '',
    ): array {
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

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_pings (room_id, sender_id, target_id, body)
VALUES (:room_id, :sender_id, :target_id, :body)
RETURNING id, created_at
SQL);
            if ($insert === false) {
                throw new RuntimeException('Unable to prepare ping insert.');
            }
            $insert->execute([
                'room_id' => $roomId,
                'sender_id' => $actor->id,
                'target_id' => $targetUserId,
                'body' => $message,
            ]);
            $row = $insert->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Ping insert did not return a row.');
            }

            $ping = [
                'id' => (int) $row['id'],
                'room_id' => $roomId,
                'sender' => ['id' => $actor->id, 'username' => $actor->username],
                'target' => ['id' => $targetUserId, 'username' => (string) $target['username']],
                'message' => $message,
                'created_at' => (new DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            ];

            // The notification references the ping; it never copies the ping text.
            $notify = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json)
VALUES (:user_id, 'pinged', CAST(:context AS jsonb))
SQL);
            if ($notify === false) {
                throw new RuntimeException('Unable to prepare ping notification insert.');
            }
            $notify->execute([
                'user_id' => $targetUserId,
                'context' => json_encode([
                    'ping_id' => $ping['id'],
                    'room_id' => $roomId,
                    'room_name' => $room->name,
                    'sender_user_id' => $actor->id,
                    'sender_username' => $actor->username,
                ], JSON_THROW_ON_ERROR),
            ]);

            // One targeted event per participant, so the sender's other tabs show it too.
            foreach ([$targetUserId, $actor->id] as $recipient) {
                $this->events->publish(
                    type: 'ping',
                    payload: ['ping' => $ping],
                    roomId: $roomId,
                    targetUserId: $recipient,
                    actorUserId: $actor->id,
                    expiresAt: new DateTimeImmutable('+1 day'),
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $ping;
    }

    /**
     * The newest pings in a room that the viewer sent or received, oldest first.
     *
     * @return list<array{id:int, room_id:int, sender:array{id:int, username:string}, target:array{id:int, username:string}, message:string, created_at:string}>
     */
    public function history(AuthenticatedUser $actor, int $roomId): array
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        RoomAuthorization::requireView($actor, $room);

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT * FROM (
    SELECT p.id, p.room_id, p.body, p.created_at,
           p.sender_id, sender.username AS sender_username,
           p.target_id, target.username AS target_username
    FROM room_pings p
    JOIN users sender ON sender.id = p.sender_id
    JOIN users target ON target.id = p.target_id
    WHERE p.room_id = :room_id
      AND (p.sender_id = :sender_viewer OR p.target_id = :target_viewer)
    ORDER BY p.id DESC
    LIMIT :limit
) recent
ORDER BY id ASC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ping history.');
        }
        $statement->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $statement->bindValue(':sender_viewer', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':target_viewer', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':limit', self::HISTORY_LIMIT, PDO::PARAM_INT);
        $statement->execute();

        $pings = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pings[] = [
                'id' => (int) $row['id'],
                'room_id' => (int) $row['room_id'],
                'sender' => ['id' => (int) $row['sender_id'], 'username' => (string) $row['sender_username']],
                'target' => ['id' => (int) $row['target_id'], 'username' => (string) $row['target_username']],
                'message' => (string) $row['body'],
                'created_at' => (new DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            ];
        }

        return $pings;
    }
}
