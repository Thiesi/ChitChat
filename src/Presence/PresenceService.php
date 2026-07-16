<?php

declare(strict_types=1);

namespace ChitChat\Presence;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\Room;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use PDO;
use RuntimeException;
use Throwable;

final class PresenceService
{
    private readonly RoomRepository $rooms;
    private readonly EventRepository $events;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->rooms = new RoomRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    /**
     * @return array{room_id:?int, idle_seconds:int, warning_seconds:?int, expired:bool, lease_seconds:int}
     */
    public function heartbeat(
        AuthenticatedUser $actor,
        string $connectionId,
        ?int $roomId,
        bool $interacted,
    ): array {
        $connectionId = $this->validateConnectionId($connectionId);
        $this->expireStale();
        $room = $roomId === null ? null : $this->requirePresenceRoom($actor, $roomId);

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(<<<'SQL'
SELECT user_id,
       room_id,
       GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - last_interaction_at))))::integer AS idle_seconds
FROM room_presence
WHERE connection_id = CAST(:connection_id AS uuid)
FOR UPDATE
SQL);
            if ($lookup === false) {
                throw new RuntimeException('Unable to prepare presence lookup.');
            }
            $lookup->execute(['connection_id' => $connectionId]);
            $row = $lookup->fetch();
            $existing = is_array($row) ? $row : null;
            if ($existing !== null && (int) $existing['user_id'] !== $actor->id) {
                throw new ApiException(409, 'presence_connection_conflict', 'That presence connection belongs to another user.');
            }

            $oldRoomId = $existing !== null && $existing['room_id'] !== null
                ? (int) $existing['room_id']
                : null;
            $idleSeconds = $existing !== null ? (int) $existing['idle_seconds'] : 0;
            $effectiveRoomId = $room?->id;
            $expired = false;

            if (
                $room !== null
                && $oldRoomId === $room->id
                && $room->inactivityTimeoutSeconds > 0
                && $idleSeconds >= $room->inactivityTimeoutSeconds
                && !$interacted
            ) {
                $effectiveRoomId = null;
                $expired = true;
            }

            $touchInteraction = $interacted
                || $existing === null
                || $oldRoomId !== $effectiveRoomId;

            if ($existing === null) {
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_presence (
    connection_id,
    user_id,
    room_id,
    connected_at,
    last_seen_at,
    last_interaction_at,
    lease_expires_at
)
VALUES (
    CAST(:connection_id AS uuid),
    :user_id,
    :room_id,
    NOW(),
    NOW(),
    NOW(),
    NOW() + CAST(:lease_seconds AS integer) * INTERVAL '1 second'
)
SQL);
                if ($insert === false) {
                    throw new RuntimeException('Unable to prepare presence creation.');
                }
                $insert->execute([
                    'connection_id' => $connectionId,
                    'user_id' => $actor->id,
                    'room_id' => $effectiveRoomId,
                    'lease_seconds' => $this->config->presenceLeaseSeconds,
                ]);
                $idleSeconds = 0;
            } else {
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE room_presence
SET room_id = :room_id,
    last_seen_at = NOW(),
    last_interaction_at = CASE
        WHEN CAST(:touch_interaction AS integer) = 1 THEN NOW()
        ELSE last_interaction_at
    END,
    lease_expires_at = NOW() + CAST(:lease_seconds AS integer) * INTERVAL '1 second'
WHERE connection_id = CAST(:connection_id AS uuid)
  AND user_id = :user_id
SQL);
                if ($update === false) {
                    throw new RuntimeException('Unable to prepare presence renewal.');
                }
                $update->execute([
                    'room_id' => $effectiveRoomId,
                    'touch_interaction' => $touchInteraction ? 1 : 0,
                    'lease_seconds' => $this->config->presenceLeaseSeconds,
                    'connection_id' => $connectionId,
                    'user_id' => $actor->id,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Presence renewal did not update a connection.');
                }
                if ($touchInteraction) {
                    $idleSeconds = 0;
                }
            }

            if ($oldRoomId !== $effectiveRoomId) {
                if ($oldRoomId !== null) {
                    $this->publishChanged($oldRoomId, $actor->id);
                }
                if ($effectiveRoomId !== null) {
                    $this->publishChanged($effectiveRoomId, $actor->id);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $warningSeconds = null;
        if ($effectiveRoomId !== null && $room !== null && $room->inactivityTimeoutSeconds > 0) {
            $remaining = max(0, $room->inactivityTimeoutSeconds - $idleSeconds);
            if ($remaining <= $this->config->inactivityWarningSeconds) {
                $warningSeconds = $remaining;
            }
        }

        return [
            'room_id' => $effectiveRoomId,
            'idle_seconds' => $idleSeconds,
            'warning_seconds' => $warningSeconds,
            'expired' => $expired,
            'lease_seconds' => $this->config->presenceLeaseSeconds,
        ];
    }

    /** @return list<array{id:int, username:string, idle_seconds:int, connections:int}> */
    public function list(AuthenticatedUser $actor, int $roomId): array
    {
        $room = $this->requirePresenceRoom($actor, $roomId);
        $this->expireStale();

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id,
       u.username,
       MIN(GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - p.last_interaction_at)))))::integer AS idle_seconds,
       COUNT(*)::integer AS connections
FROM room_presence p
JOIN users u ON u.id = p.user_id
WHERE p.room_id = :room_id
  AND p.lease_expires_at > NOW()
  AND (
      CAST(:inactivity_timeout AS integer) = 0
      OR p.last_interaction_at > NOW() - CAST(:inactivity_window AS integer) * INTERVAL '1 second'
  )
GROUP BY u.id, u.username
ORDER BY lower(u.username), u.id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room presence list.');
        }
        $statement->execute([
            'room_id' => $room->id,
            'inactivity_timeout' => $room->inactivityTimeoutSeconds,
            'inactivity_window' => $room->inactivityTimeoutSeconds,
        ]);

        $users = [];
        foreach ($statement->fetchAll() as $presenceRow) {
            if (!is_array($presenceRow)) {
                continue;
            }
            $users[] = [
                'id' => (int) $presenceRow['id'],
                'username' => (string) $presenceRow['username'],
                'idle_seconds' => (int) $presenceRow['idle_seconds'],
                'connections' => (int) $presenceRow['connections'],
            ];
        }

        return $users;
    }

    public function expireStale(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM room_presence
WHERE lease_expires_at <= NOW()
RETURNING room_id, user_id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare stale-presence cleanup.');
            }
            $statement->execute();

            /** @var array<string, array{int, int}> $changed */
            $changed = [];
            foreach ($statement->fetchAll() as $expiredRow) {
                if (!is_array($expiredRow) || $expiredRow['room_id'] === null) {
                    continue;
                }
                $key = (int) $expiredRow['room_id'] . ':' . (int) $expiredRow['user_id'];
                $changed[$key] = [(int) $expiredRow['room_id'], (int) $expiredRow['user_id']];
            }

            foreach ($changed as [$changedRoomId, $changedUserId]) {
                $this->publishChanged($changedRoomId, $changedUserId);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requirePresenceRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }

        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        if (!$room->isMember() && !RoomAuthorization::canModerateAnyRoom($actor)) {
            throw new ApiException(403, 'presence_membership_required', 'Room membership is required for presence.');
        }
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        return $room;
    }

    private function validateConnectionId(string $connectionId): string
    {
        $connectionId = strtolower(trim($connectionId));
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $connectionId,
        ) !== 1) {
            throw new ApiException(400, 'invalid_connection_id', 'connection_id must be a UUID.');
        }

        return $connectionId;
    }

    private function publishChanged(int $roomId, int $userId): void
    {
        $this->events->publish(
            type: 'presence_changed',
            payload: ['room_id' => $roomId, 'user_id' => $userId],
            roomId: $roomId,
            actorUserId: $userId,
        );
    }
}
