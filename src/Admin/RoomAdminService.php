<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\Room;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use PDO;
use RuntimeException;
use Throwable;

final class RoomAdminService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
    }

    /**
     * @return array{
     *   room:array{id:int, key:string, name:string, info_line:string, visibility:string, minimum_age:int, inactivity_timeout_seconds:int, created_by:int, member_role:?string, invited:bool},
     *   members:list<array{id:int, username:string, role:string, joined_at:string, active_connections:int}>,
     *   invitations:list<array{id:int, username:string, invited_by:int, invited_by_username:string, created_at:string}>
     * }
     */
    public function snapshot(AuthenticatedUser $actor, int $roomId): array
    {
        $room = $this->requireManagedRoom($actor, $roomId);

        $membersStatement = $this->pdo->prepare(<<<'SQL'
SELECT u.id,
       u.username,
       rm.role,
       rm.joined_at,
       (COUNT(p.connection_id) FILTER (WHERE p.lease_expires_at > NOW()))::integer AS active_connections
FROM room_members rm
JOIN users u ON u.id = rm.user_id
LEFT JOIN room_presence p
       ON p.room_id = rm.room_id
      AND p.user_id = rm.user_id
WHERE rm.room_id = :room_id
GROUP BY u.id, u.username, rm.role, rm.joined_at
ORDER BY CASE rm.role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 ELSE 2 END,
         lower(u.username),
         u.id
SQL);
        if ($membersStatement === false) {
            throw new RuntimeException('Unable to prepare room member administration list.');
        }
        $membersStatement->execute(['room_id' => $roomId]);
        $members = [];
        foreach ($membersStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $members[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'role' => (string) $row['role'],
                'joined_at' => (string) $row['joined_at'],
                'active_connections' => (int) $row['active_connections'],
            ];
        }

        $invitationsStatement = $this->pdo->prepare(<<<'SQL'
SELECT invited.id,
       invited.username,
       ri.invited_by,
       inviter.username AS invited_by_username,
       ri.created_at
FROM room_invitations ri
JOIN users invited ON invited.id = ri.user_id
JOIN users inviter ON inviter.id = ri.invited_by
WHERE ri.room_id = :room_id
ORDER BY ri.created_at DESC, invited.id
SQL);
        if ($invitationsStatement === false) {
            throw new RuntimeException('Unable to prepare room invitation administration list.');
        }
        $invitationsStatement->execute(['room_id' => $roomId]);
        $invitations = [];
        foreach ($invitationsStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $invitations[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'invited_by' => (int) $row['invited_by'],
                'invited_by_username' => (string) $row['invited_by_username'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return [
            'room' => $room->toArray(),
            'members' => $members,
            'invitations' => $invitations,
        ];
    }

    /** @return list<array{id:int, username:string}> */
    public function searchInvitable(
        AuthenticatedUser $actor,
        int $roomId,
        string $search,
        int $limit = 20,
    ): array {
        $this->requireManagedRoom($actor, $roomId);
        $search = trim($search);
        if (mb_strlen($search, 'UTF-8') < 2 || mb_strlen($search, 'UTF-8') > 32) {
            throw new ApiException(400, 'validation_error', 'search must contain 2-32 characters.');
        }
        if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $search) !== 1) {
            throw new ApiException(400, 'validation_error', 'search contains unsupported characters.');
        }
        if ($limit < 1 || $limit > 50) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 50.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.username
FROM users u
WHERE lower(u.username) LIKE :pattern
  AND NOT EXISTS (
      SELECT 1 FROM room_members rm
      WHERE rm.room_id = :member_room_id AND rm.user_id = u.id
  )
  AND NOT EXISTS (
      SELECT 1 FROM room_invitations ri
      WHERE ri.room_id = :invitation_room_id AND ri.user_id = u.id
  )
ORDER BY lower(u.username), u.id
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room invitation search.');
        }
        $statement->bindValue(':pattern', strtolower($search) . '%');
        $statement->bindValue(':member_room_id', $roomId, PDO::PARAM_INT);
        $statement->bindValue(':invitation_room_id', $roomId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $users = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $users[] = ['id' => (int) $row['id'], 'username' => (string) $row['username']];
        }

        return $users;
    }

    public function removeMember(
        AuthenticatedUser $actor,
        int $roomId,
        int $targetUserId,
        string $ipAddress,
    ): void {
        $this->requireManagedRoom($actor, $roomId);
        $role = $this->rooms->membershipRole($roomId, $targetUserId);
        if ($role === null) {
            throw new ApiException(404, 'membership_not_found', 'Target user is not a room member.');
        }
        if ($role === 'owner') {
            throw new ApiException(409, 'owner_membership_immutable', 'The room owner cannot be removed.');
        }

        $this->pdo->beginTransaction();
        try {
            $presence = $this->pdo->prepare(
                'DELETE FROM room_presence WHERE room_id = :room_id AND user_id = :user_id',
            );
            if ($presence === false) {
                throw new RuntimeException('Unable to prepare room presence removal.');
            }
            $presence->execute(['room_id' => $roomId, 'user_id' => $targetUserId]);

            $membership = $this->pdo->prepare(
                'DELETE FROM room_members WHERE room_id = :room_id AND user_id = :user_id',
            );
            if ($membership === false) {
                throw new RuntimeException('Unable to prepare room membership removal.');
            }
            $membership->execute(['room_id' => $roomId, 'user_id' => $targetUserId]);
            if ($membership->rowCount() !== 1) {
                throw new RuntimeException('Room membership removal did not affect one row.');
            }

            $this->audit->log(
                actorUserId: $actor->id,
                action: 'room.member_removed',
                subjectType: 'room',
                subjectId: (string) $roomId,
                metadata: ['target_user_id' => $targetUserId, 'old_role' => $role],
                ipAddress: $ipAddress,
            );
            $this->events->publish(
                type: 'presence_changed',
                payload: ['room_id' => $roomId, 'user_id' => $targetUserId],
                roomId: $roomId,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeInvitation(
        AuthenticatedUser $actor,
        int $roomId,
        int $targetUserId,
        string $ipAddress,
    ): void {
        $this->requireManagedRoom($actor, $roomId);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM room_invitations WHERE room_id = :room_id AND user_id = :user_id',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare invitation revocation.');
            }
            $statement->execute(['room_id' => $roomId, 'user_id' => $targetUserId]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException(404, 'invitation_not_found', 'Pending invitation not found.');
            }
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'room.invitation_revoked',
                subjectType: 'room',
                subjectId: (string) $roomId,
                metadata: ['target_user_id' => $targetUserId],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requireManagedRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        RoomAuthorization::requireManage($actor, $room);

        return $room;
    }
}
