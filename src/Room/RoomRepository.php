<?php

declare(strict_types=1);

namespace ChitChat\Room;

use PDO;
use RuntimeException;

final class RoomRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findForUser(int $roomId, int $userId): ?Room
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id,
       r.room_key,
       r.name,
       r.info_line,
       r.visibility,
       r.minimum_age,
       r.inactivity_timeout_seconds,
       r.created_by,
       rm.role AS member_role,
       (ri.user_id IS NOT NULL)::int AS invited
FROM rooms r
LEFT JOIN room_members rm
       ON rm.room_id = r.id AND rm.user_id = :user_id
LEFT JOIN room_invitations ri
       ON ri.room_id = r.id AND ri.user_id = :user_id
WHERE r.id = :room_id
  AND r.deleted_at IS NULL
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room lookup.');
        }

        $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @return list<Room> */
    public function listForUser(int $userId, bool $includeAll): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id,
       r.room_key,
       r.name,
       r.info_line,
       r.visibility,
       r.minimum_age,
       r.inactivity_timeout_seconds,
       r.created_by,
       rm.role AS member_role,
       (ri.user_id IS NOT NULL)::int AS invited
FROM rooms r
LEFT JOIN room_members rm
       ON rm.room_id = r.id AND rm.user_id = :user_id
LEFT JOIN room_invitations ri
       ON ri.room_id = r.id AND ri.user_id = :user_id
WHERE r.deleted_at IS NULL
  AND (
      CAST(:include_all AS integer) = 1
      OR r.visibility = 'public'
      OR rm.user_id IS NOT NULL
      OR ri.user_id IS NOT NULL
  )
ORDER BY lower(r.name), r.id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room list.');
        }

        $statement->execute([
            'user_id' => $userId,
            'include_all' => $includeAll ? 1 : 0,
        ]);

        $rooms = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rooms[] = $this->hydrate($row);
            }
        }

        return $rooms;
    }

    public function membershipRole(int $roomId, int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT role FROM room_members WHERE room_id = :room_id AND user_id = :user_id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room membership lookup.');
        }

        $statement->execute(['room_id' => $roomId, 'user_id' => $userId]);
        $role = $statement->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    public function birthDateForUser(int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT birth_date FROM users WHERE id = :id AND account_state = 'active'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare birth-date lookup.');
        }

        $statement->execute(['id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function userExists(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM users WHERE id = :id AND account_state = 'active'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare user existence lookup.');
        }

        $statement->execute(['id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    public function userIsMember(int $roomId, int $userId): bool
    {
        return $this->membershipRole($roomId, $userId) !== null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Room
    {
        return new Room(
            id: (int) $row['id'],
            key: (string) $row['room_key'],
            name: (string) $row['name'],
            infoLine: (string) $row['info_line'],
            visibility: (string) $row['visibility'],
            minimumAge: (int) $row['minimum_age'],
            inactivityTimeoutSeconds: (int) $row['inactivity_timeout_seconds'],
            createdBy: (int) $row['created_by'],
            memberRole: $row['member_role'] === null ? null : (string) $row['member_role'],
            invited: (int) $row['invited'] === 1,
        );
    }
}
