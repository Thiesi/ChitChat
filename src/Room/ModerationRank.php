<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use PDO;
use RuntimeException;

/**
 * Who may act on whom. Everyone has a rank: member 0, room moderator 1,
 * room owner 2 (both only in their room), Chat Admin and Global Moderator
 * 3, Administrator 4, Super-Administrator 5. Moderation needs a strictly
 * higher rank than the person it affects; only Super-Administrators may act
 * on their equals. Nobody acts on themselves.
 */
final class ModerationRank
{
    public const SUPER_ADMINISTRATOR = 5;

    private const GLOBAL_RANKS = ['super_admin' => 5, 'admin' => 4, 'chat_admin' => 3, 'global_moderator' => 3];
    private const ROOM_RANKS = ['owner' => 2, 'moderator' => 1];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function globalRankOf(AuthenticatedUser $user): int
    {
        $rank = 0;
        foreach ($user->roles as $role) {
            $rank = max($rank, self::GLOBAL_RANKS[$role] ?? 0);
        }

        return $rank;
    }

    /** Whether `actor` may moderate `targetId` in `room` (delete their messages, mute them there). */
    public function inRoom(AuthenticatedUser $actor, Room $room, int $targetId): bool
    {
        return $this->inRoomFor($actor, $room, [$targetId])[$targetId] ?? false;
    }

    /**
     * @param list<int> $targetIds
     * @return array<int, bool> by target ID
     */
    public function inRoomFor(AuthenticatedUser $actor, Room $room, array $targetIds): array
    {
        $actorRank = max(self::globalRankOf($actor), self::ROOM_RANKS[$room->memberRole ?? ''] ?? 0);
        $result = [];
        foreach ($this->ranks($targetIds, $room->id) as $targetId => $ranks) {
            $result[$targetId] = $this->outranks($actor->id, $actorRank, $targetId, max($ranks['global'], $ranks['room']));
        }

        return $result;
    }

    /** Whether `actor` may act on `targetId` installation-wide (mute everywhere, sign out, ban). */
    public function globally(AuthenticatedUser $actor, int $targetId): bool
    {
        $ranks = $this->ranks([$targetId], null)[$targetId] ?? null;

        return $ranks !== null && $this->outranks($actor->id, self::globalRankOf($actor), $targetId, $ranks['global']);
    }

    private function outranks(int $actorId, int $actorRank, int $targetId, int $targetRank): bool
    {
        if ($actorId === $targetId || $actorRank === 0) {
            return false;
        }

        return $actorRank >= self::SUPER_ADMINISTRATOR || $actorRank > $targetRank;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{global:int, room:int}>
     */
    private function ranks(array $userIds, ?int $roomId): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = ['room_id' => $roomId];
        foreach ($userIds as $index => $userId) {
            $placeholders[] = ':user_' . $index;
            $parameters['user_' . $index] = $userId;
        }
        $list = implode(', ', $placeholders);
        $statement = $this->pdo->prepare(<<<SQL
SELECT u.id,
       COALESCE(MAX(CASE ur.role
           WHEN 'super_admin' THEN 5
           WHEN 'admin' THEN 4
           WHEN 'chat_admin' THEN 3
           WHEN 'global_moderator' THEN 3
           ELSE 0 END), 0) AS global_rank,
       COALESCE(MAX(CASE rm.role WHEN 'owner' THEN 2 WHEN 'moderator' THEN 1 ELSE 0 END), 0) AS room_rank
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN room_members rm ON rm.user_id = u.id AND rm.room_id = CAST(:room_id AS bigint)
WHERE u.id IN ({$list})
GROUP BY u.id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare moderation rank lookup.');
        }
        $statement->execute($parameters);
        $ranks = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $ranks[(int) $row['id']] = ['global' => (int) $row['global_rank'], 'room' => (int) $row['room_rank']];
            }
        }

        return $ranks;
    }
}
