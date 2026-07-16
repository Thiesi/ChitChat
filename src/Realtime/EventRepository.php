<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class EventRepository
{
    private const TYPES = [
        'room_message',
        'message_deleted',
        'ping',
        'room_broadcast',
        'global_broadcast',
        'forced_logout',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function publish(
        string $type,
        array $payload,
        ?int $roomId = null,
        ?int $targetUserId = null,
        ?int $actorUserId = null,
        ?DateTimeImmutable $expiresAt = null,
    ): RealtimeEvent {
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiException(400, 'invalid_event_type', 'Unsupported realtime event type.');
        }

        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode realtime event payload.', 0, $exception);
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO realtime_events (
    event_type,
    room_id,
    target_user_id,
    actor_user_id,
    payload,
    expires_at
)
VALUES (
    :event_type,
    :room_id,
    :target_user_id,
    :actor_user_id,
    CAST(:payload AS jsonb),
    :expires_at
)
RETURNING id, event_type, room_id, target_user_id, actor_user_id, payload::text AS payload, created_at
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare realtime event publication.');
        }

        $statement->execute([
            'event_type' => $type,
            'room_id' => $roomId,
            'target_user_id' => $targetUserId,
            'actor_user_id' => $actorUserId,
            'payload' => $encodedPayload,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Published realtime event could not be reloaded.');
        }

        return $this->hydrate($row);
    }

    /** @return list<RealtimeEvent> */
    public function visibleAfter(AuthenticatedUser $actor, int $afterId, int $limit = 100): array
    {
        if ($afterId < 0) {
            throw new ApiException(400, 'validation_error', 'after_id must not be negative.');
        }
        if ($limit < 1 || $limit > 250) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 250.');
        }

        $isGlobalModerator = $actor->hasRole('super_admin')
            || $actor->hasRole('admin')
            || $actor->hasRole('chat_admin')
            || $actor->hasRole('global_moderator');

        $visibility = $isGlobalModerator
            ? <<<'SQL'
(
    e.target_user_id = :user_id
    OR (
        e.target_user_id IS NULL
        AND (e.room_id IS NULL OR e.room_id IS NOT NULL)
    )
)
SQL
            : <<<'SQL'
(
    e.target_user_id = :user_id
    OR (
        e.target_user_id IS NULL
        AND e.room_id IS NULL
    )
    OR (
        e.target_user_id IS NULL
        AND e.room_id IS NOT NULL
        AND EXISTS (
            SELECT 1
            FROM room_members rm
            WHERE rm.room_id = e.room_id
              AND rm.user_id = :user_id
        )
    )
)
SQL;

        $statement = $this->pdo->prepare(<<<SQL
SELECT e.id,
       e.event_type,
       e.room_id,
       e.target_user_id,
       e.actor_user_id,
       e.payload::text AS payload,
       e.created_at
FROM realtime_events e
WHERE e.id > :after_id
  AND (e.expires_at IS NULL OR e.expires_at > NOW())
  AND {$visibility}
ORDER BY e.id ASC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare realtime event lookup.');
        }
        $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $events[] = $this->hydrate($row);
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RealtimeEvent
    {
        try {
            $payload = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored realtime event payload is invalid.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Stored realtime event payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return new RealtimeEvent(
            id: (int) $row['id'],
            type: (string) $row['event_type'],
            roomId: $row['room_id'] === null ? null : (int) $row['room_id'],
            targetUserId: $row['target_user_id'] === null ? null : (int) $row['target_user_id'],
            actorUserId: $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            payload: $payload,
            createdAt: (string) $row['created_at'],
        );
    }
}
