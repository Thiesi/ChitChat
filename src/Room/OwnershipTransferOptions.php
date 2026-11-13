<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * Whether a viewer may hand a room to each of some people now (#302), for the profile card and
 * the room administration snapshot. The same rule as the transfer (OwnershipTransferRule), read
 * without locks: "allowed now, after step-up", and it can go stale, so clients still handle the
 * endpoint's refusals.
 *
 * Bans are visible only to viewers who can manage users (the user directory). Everyone else
 * gets a flag that ignores bans, so it reveals nothing the profile doesn't: a guest, or an
 * account that is closing or closed (the profile then answers 404), is already visible. The
 * transfer refuses a banned target with 409 at action time, behind step-up.
 */
final class OwnershipTransferOptions
{
    private const GLOBAL_RANK = <<<'SQL'
(SELECT COALESCE(MAX(CASE r.role
    WHEN 'super_admin' THEN 5 WHEN 'admin' THEN 4 WHEN 'chat_admin' THEN 3 WHEN 'global_moderator' THEN 3
    ELSE 0 END), 0) FROM user_roles r WHERE r.user_id = u.id)
SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $targetIds
     * @return array<int, bool> per target: whether the viewer may make them the owner now
     */
    public function forTargets(AuthenticatedUser $viewer, Room $room, array $targetIds): array
    {
        $allowed = array_fill_keys($targetIds, false);
        if ($targetIds === [] || !RoomAuthorization::canManage($viewer, $room)) {
            return $allowed;
        }

        $owner = $this->owner($room->id);
        $managesAnyRoom = RoomAuthorization::canManageAnyRoom($viewer);
        $viewerRank = ModerationRank::globalRankOf($viewer);
        $seesBans = $viewer->canManageUsers();
        $ageRefusal = null;
        $age = function () use ($viewer, $room, &$ageRefusal): ?ApiException {
            $ageRefusal ??= [(new RoomService($this->pdo))->ageRefusal($viewer, $room->minimumAge)];

            return $ageRefusal[0];
        };

        $targets = $this->targets($room->id, $targetIds);
        foreach ($targetIds as $targetId) {
            $target = $targets[$targetId] ?? null;
            $eligible = $target !== null
                && $target['kind'] === 'member'
                && $target['state'] === 'active'
                && (!$target['banned'] || (!$seesBans && $targetId !== $viewer->id));
            $allowed[$targetId] = OwnershipTransferRule::refusal(
                $viewer->id,
                $managesAnyRoom,
                $viewerRank,
                $targetId,
                $target['role'] ?? null,
                $eligible,
                $owner['id'] ?? null,
                $owner['active'] ?? false,
                $owner['rank'] ?? 0,
                $age,
            ) === null;
        }

        return $allowed;
    }

    public function forTarget(AuthenticatedUser $viewer, Room $room, int $targetId): bool
    {
        return $this->forTargets($viewer, $room, [$targetId])[$targetId];
    }

    /** Whether the viewer may make themselves the owner now, also of a room they aren't in. */
    public function canTakeOwnership(AuthenticatedUser $viewer, Room $room): bool
    {
        return $this->forTarget($viewer, $room, $viewer->id);
    }

    /** @return ?array{id:int, active:bool, rank:int} */
    private function owner(int $roomId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.account_state, " . self::GLOBAL_RANK . " AS rank
             FROM room_members rm JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id = :room_id AND rm.role = 'owner'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the room owner lookup.');
        }
        $statement->execute(['room_id' => $roomId]);
        $row = $statement->fetch();

        return is_array($row)
            ? ['id' => (int) $row['id'], 'active' => (string) $row['account_state'] === 'active', 'rank' => (int) $row['rank']]
            : null;
    }

    /**
     * @param list<int> $targetIds
     * @return array<int, array{kind:string, state:string, banned:bool, role:?string}>
     */
    private function targets(int $roomId, array $targetIds): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.account_kind, u.account_state, rm.role,
       EXISTS (
           SELECT 1 FROM user_bans b
           WHERE b.user_id = u.id AND b.revoked_at IS NULL
             AND b.starts_at <= NOW() AND (b.expires_at IS NULL OR b.expires_at > NOW())
       ) AS banned
FROM users u
LEFT JOIN room_members rm ON rm.room_id = :room_id AND rm.user_id = u.id
WHERE u.id = ANY (CAST(:ids AS bigint[]))
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the transfer target lookup.');
        }
        $statement->execute(['room_id' => $roomId, 'ids' => '{' . implode(',', array_map('intval', $targetIds)) . '}']);
        $targets = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targets[(int) $row['id']] = [
                'kind' => (string) $row['account_kind'],
                'state' => (string) $row['account_state'],
                'banned' => $row['banned'] === true || $row['banned'] === 1 || $row['banned'] === 't' || $row['banned'] === '1',
                'role' => $row['role'] === null ? null : (string) $row['role'],
            ];
        }

        return $targets;
    }
}
