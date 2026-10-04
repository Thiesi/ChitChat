<?php

declare(strict_types=1);

namespace ChitChat\Moderation;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\ModerationRank;
use ChitChat\Room\Room;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomListSignal;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Muting: someone can still sign in and read, but not post. In one room,
 * room owners and moderators (and global staff) may mute anyone of a lower
 * rank there; everywhere, which also stops sending direct messages, only
 * Global Moderators and up may. The muted person is told why and until
 * when, and the room is told only when the moderator chooses to.
 */
final class MuteService
{
    private const GLOBAL_MODERATION_RANK = 3;

    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly ModerationRank $ranks;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->ranks = new ModerationRank($pdo);
    }

    /**
     * Mutes `targetId` in `roomId`, or everywhere when `roomId` is null, until
     * `expiresAt` (null: until lifted). A current mute in the same place is
     * replaced.
     *
     * @return array{id:int, scope:string, room_id:?int, expires_at:?string, reason:string}
     */
    public function mute(
        AuthenticatedUser $actor,
        int $targetId,
        ?int $roomId,
        ?DateTimeImmutable $expiresAt,
        string $reason,
        bool $announce,
        string $ipAddress,
    ): array {
        $reason = $this->validReason($reason);
        if ($expiresAt !== null && $expiresAt <= new DateTimeImmutable()) {
            throw new ApiException(400, 'validation_error', 'A mute must end in the future.');
        }
        $room = $roomId === null ? null : $this->requireMayMuteInRoom($actor, $roomId, $targetId);
        if ($room === null) {
            $this->requireMayMuteEverywhere($actor, $targetId);
        }
        $target = (new UserRepository($this->pdo))->findAuthenticatedById($targetId)
            ?? throw new ApiException(404, 'user_not_found', 'User not found.');

        $this->pdo->beginTransaction();
        try {
            $this->liftCurrent($targetId, $roomId, $actor->id);
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_mutes (user_id, room_id, muted_by, reason, expires_at)
VALUES (:user_id, :room_id, :muted_by, :reason, :expires_at)
RETURNING id
SQL);
            if ($insert === false) {
                throw new RuntimeException('Unable to prepare mute.');
            }
            $insert->execute([
                'user_id' => $targetId,
                'room_id' => $roomId,
                'muted_by' => $actor->id,
                'reason' => $reason,
                'expires_at' => $expiresAt?->format(DATE_ATOM),
            ]);
            $muteId = (int) $insert->fetchColumn();

            $this->audit->log($actor->id, 'moderation.mute', 'user', (string) $targetId, [
                'room_id' => $roomId,
                'expires_at' => $expiresAt?->format(DATE_ATOM),
                'reason' => $reason,
            ], $ipAddress);
            $notify = $this->pdo->prepare(
                "INSERT INTO account_notifications (user_id, kind, context_json) VALUES (:user_id, 'muted', CAST(:context AS jsonb))",
            );
            if ($notify === false) {
                throw new RuntimeException('Unable to prepare mute notification.');
            }
            $notify->execute(['user_id' => $targetId, 'context' => json_encode([
                'room_id' => $roomId,
                'room_name' => $room?->name,
                'expires_at' => $expiresAt?->format(DATE_ATOM),
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR)]);
            if ($room !== null && $announce) {
                (new MessageService($this->pdo))->postNotice(
                    $room->id,
                    sprintf('%s was muted in this room %s.', $target->username, self::spanText($expiresAt)),
                );
            }
            $this->tellTarget($targetId, $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->current($targetId, $roomId) ?? throw new RuntimeException('The new mute could not be read back.');
    }

    /** Ends a mute early, by anyone who could have set it. */
    public function lift(AuthenticatedUser $actor, int $muteId, string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, room_id FROM user_mutes WHERE id = :id AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mute lookup.');
        }
        $statement->execute(['id' => $muteId]);
        $mute = $statement->fetch();
        if (!is_array($mute)) {
            throw new ApiException(404, 'mute_not_found', 'That mute has already ended.');
        }
        $targetId = (int) $mute['user_id'];
        $roomId = $mute['room_id'] === null ? null : (int) $mute['room_id'];
        if ($roomId === null) {
            $this->requireMayMuteEverywhere($actor, $targetId);
        } else {
            $this->requireMayMuteInRoom($actor, $roomId, $targetId);
        }

        $update = $this->pdo->prepare('UPDATE user_mutes SET lifted_at = NOW(), lifted_by = :actor WHERE id = :id AND lifted_at IS NULL');
        if ($update === false) {
            throw new RuntimeException('Unable to prepare mute lift.');
        }
        $update->execute(['actor' => $actor->id, 'id' => $muteId]);
        $this->audit->log($actor->id, 'moderation.unmute', 'user', (string) $targetId, ['room_id' => $roomId], $ipAddress);
        $this->tellTarget($targetId, $actor->id);
    }

    /**
     * The current mute of `userId` in exactly that place (a room, or
     * everywhere for null).
     *
     * @return ?array{id:int, scope:string, room_id:?int, expires_at:?string, reason:string}
     */
    public function current(int $userId, ?int $roomId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, room_id, expires_at, reason
FROM user_mutes
WHERE user_id = :user_id
  AND room_id IS NOT DISTINCT FROM CAST(:room_id AS bigint)
  AND lifted_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW())
ORDER BY id DESC
LIMIT 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mute lookup.');
        }
        $statement->execute(['user_id' => $userId, 'room_id' => $roomId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every current mute of `userId`: `everywhere` and one per room.
     *
     * @return array{everywhere: ?array{id:int, scope:string, room_id:?int, expires_at:?string, reason:string}, rooms: array<int, array{id:int, scope:string, room_id:?int, expires_at:?string, reason:string}>}
     */
    public function allFor(int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT ON (room_id) id, room_id, expires_at, reason
FROM user_mutes
WHERE user_id = :user_id
  AND lifted_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW())
ORDER BY room_id, id DESC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mute lookup.');
        }
        $statement->execute(['user_id' => $userId]);
        $result = ['everywhere' => null, 'rooms' => []];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mute = $this->hydrate($row);
            if ($mute['room_id'] === null) {
                $result['everywhere'] = $mute;
            } else {
                $result['rooms'][$mute['room_id']] = $mute;
            }
        }

        return $result;
    }

    /** Throws for someone muted in this room or everywhere. */
    public function assertMayPostInRoom(int $userId, int $roomId): void
    {
        if ($this->current($userId, null) !== null) {
            throw new ApiException(403, 'muted', 'You are muted, so you cannot post or send direct messages right now.');
        }
        if ($this->current($userId, $roomId) !== null) {
            throw new ApiException(403, 'muted', 'You are muted in this room, so you cannot post here right now.');
        }
    }

    /** Throws for someone muted everywhere. */
    public function assertMaySendDirect(int $userId): void
    {
        if ($this->current($userId, null) !== null) {
            throw new ApiException(403, 'muted', 'You are muted, so you cannot post or send direct messages right now.');
        }
    }

    public function isMutedInRoom(int $userId, int $roomId): bool
    {
        return $this->current($userId, null) !== null || $this->current($userId, $roomId) !== null;
    }

    public function canMuteEverywhere(AuthenticatedUser $actor, int $targetId): bool
    {
        return ModerationRank::globalRankOf($actor) >= self::GLOBAL_MODERATION_RANK && $this->ranks->globally($actor, $targetId);
    }

    public function canMuteInRoom(AuthenticatedUser $actor, Room $room, int $targetId): bool
    {
        return RoomAuthorization::canModerate($actor, $room) && $this->ranks->inRoom($actor, $room, $targetId);
    }

    /** "for 1 hour", "for 10 days", or "until further notice", for notices that every viewer reads alike. */
    public static function spanText(?DateTimeImmutable $expiresAt): string
    {
        if ($expiresAt === null) {
            return 'until further notice';
        }
        $minutes = max(1, (int) round(($expiresAt->getTimestamp() - time()) / 60));
        [$count, $unit] = match (true) {
            $minutes >= 2 * 1440 || $minutes % 1440 === 0 => [(int) round($minutes / 1440), 'day'],
            $minutes >= 2 * 60 || $minutes % 60 === 0 => [(int) round($minutes / 60), 'hour'],
            default => [$minutes, 'minute'],
        };

        return sprintf('for %d %s%s', $count, $unit, $count === 1 ? '' : 's');
    }

    private function requireMayMuteInRoom(AuthenticatedUser $actor, int $roomId, int $targetId): Room
    {
        $room = (new RoomRepository($this->pdo))->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        if (!$this->canMuteInRoom($actor, $room, $targetId)) {
            throw new ApiException(403, 'forbidden', 'You cannot mute this person in this room.');
        }

        return $room;
    }

    private function requireMayMuteEverywhere(AuthenticatedUser $actor, int $targetId): void
    {
        if (!$this->canMuteEverywhere($actor, $targetId)) {
            throw new ApiException(403, 'forbidden', 'You cannot mute this person everywhere.');
        }
    }

    private function liftCurrent(int $userId, ?int $roomId, int $actorId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE user_mutes
SET lifted_at = NOW(), lifted_by = :actor
WHERE user_id = :user_id
  AND room_id IS NOT DISTINCT FROM CAST(:room_id AS bigint)
  AND lifted_at IS NULL
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mute replacement.');
        }
        $statement->execute(['actor' => $actorId, 'user_id' => $userId, 'room_id' => $roomId]);
    }

    /** A personal signal, so the muted person's open pages show (or drop) the mute at once. */
    private function tellTarget(int $targetId, int $actorId): void
    {
        (new RoomListSignal($this->events))->user($targetId, $actorId);
    }

    private function validReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ApiException(400, 'validation_error', 'The reason must not exceed 500 characters.');
        }

        return $reason;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, scope:string, room_id:?int, expires_at:?string, reason:string}
     */
    private function hydrate(array $row): array
    {
        $roomId = $row['room_id'] === null ? null : (int) $row['room_id'];

        return [
            'id' => (int) $row['id'],
            'scope' => $roomId === null ? 'everywhere' : 'room',
            'room_id' => $roomId,
            'expires_at' => $row['expires_at'] === null ? null : (new DateTimeImmutable((string) $row['expires_at']))->format(DATE_ATOM),
            'reason' => (string) $row['reason'],
        ];
    }
}
