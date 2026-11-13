<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class RoomService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;
    private readonly RoomListSignal $roomList;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->roomList = new RoomListSignal(new EventRepository($pdo));
    }

    /** @return list<array{id:int, key:string, name:string, info_line:string, visibility:string, minimum_age:int, inactivity_timeout_seconds:int, created_by:int, member_role:?string, invited:bool, unread_count:int, last_read_message_id:?int, unread_mention_count:int, unread_through_message_id:?int, unread_settled_message_id:?int, unread_counted_ids:list<int>, active_count:?int, last_activity_at:?string}> */
    public function list(AuthenticatedUser $actor): array
    {
        $includeAll = RoomAuthorization::canModerateAnyRoom($actor);
        $reads = (new RoomReadService($this->pdo))->forUser($actor->id);
        $mutes = (new MuteService($this->pdo))->allFor($actor->id);
        $activity = (new RoomListActivity($this->pdo))->forUser($actor);
        // Unread counts only for joined rooms; others have nothing to count.
        return array_map(
            static fn (Room $room): array => $room->toArray() + [
                'unread_count' => $room->isMember() ? ($reads[$room->id]['unread_count'] ?? 0) : 0,
                'last_read_message_id' => $room->isMember() ? ($reads[$room->id]['last_read_message_id'] ?? null) : null,
                'unread_mention_count' => $room->isMember() ? ($reads[$room->id]['unread_mention_count'] ?? 0) : 0,
                'unread_through_message_id' => $room->isMember() ? ($reads[$room->id]['unread_through_message_id'] ?? null) : null,
                'unread_settled_message_id' => $room->isMember() ? ($reads[$room->id]['unread_settled_message_id'] ?? null) : null,
                'unread_counted_ids' => $room->isMember() ? ($reads[$room->id]['unread_counted_ids'] ?? []) : [],
                'active_count' => $room->isMember() ? ($activity[$room->id]['active_count'] ?? null) : null,
                'last_activity_at' => $room->isMember() ? ($activity[$room->id]['last_activity_at'] ?? null) : null,
                // Muted everywhere counts in every room.
                'muted' => $mutes['everywhere'] ?? $mutes['rooms'][$room->id] ?? null,
            ],
            $this->rooms->listForUser($actor->id, $includeAll),
        );
    }

    public function get(AuthenticatedUser $actor, int $roomId): Room
    {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireView($actor, $room);
        return $room;
    }

    public function create(
        AuthenticatedUser $actor,
        string $keyInput,
        string $nameInput,
        string $infoInput,
        string $visibility,
        int $minimumAge,
        int $inactivityTimeoutSeconds,
        string $ipAddress,
        string $guestAccess = 'none',
    ): Room {
        RoomAuthorization::requireCreate($actor);
        $key = RoomKey::normalize($keyInput);
        $name = $this->validateName($nameInput);
        $info = $this->validateInfo($infoInput);
        $visibility = $this->validateVisibility($visibility);
        $minimumAge = $this->validateMinimumAge($minimumAge);
        $inactivityTimeoutSeconds = $this->validateInactivityTimeout($inactivityTimeoutSeconds);
        $guestAccess = $this->validateGuestAccess($guestAccess, $visibility, $minimumAge);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO rooms (
    room_key,
    name,
    info_line,
    visibility,
    minimum_age,
    inactivity_timeout_seconds,
    created_by,
    guest_access
)
VALUES (
    :room_key,
    :name,
    :info_line,
    :visibility,
    :minimum_age,
    :inactivity_timeout_seconds,
    :created_by,
    :guest_access
)
RETURNING id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room creation.');
            }
            $statement->execute([
                'room_key' => $key,
                'name' => $name,
                'info_line' => $info,
                'visibility' => $visibility,
                'minimum_age' => $minimumAge,
                'inactivity_timeout_seconds' => $inactivityTimeoutSeconds,
                'created_by' => $actor->id,
                'guest_access' => $guestAccess,
            ]);
            $roomIdValue = $statement->fetchColumn();
            if ($roomIdValue === false) {
                throw new RuntimeException('Room creation did not return an ID.');
            }
            $roomId = (int) $roomIdValue;

            $memberStatement = $this->pdo->prepare(
                "INSERT INTO room_members (room_id, user_id, role) VALUES (:room_id, :user_id, 'owner')",
            );
            if ($memberStatement === false) {
                throw new RuntimeException('Unable to prepare room ownership.');
            }
            $memberStatement->execute(['room_id' => $roomId, 'user_id' => $actor->id]);
            (new RoomReadService($this->pdo))->startAt($actor->id, $roomId);

            $this->audit->log(
                $actor->id,
                'room.create',
                'room',
                (string) $roomId,
                [
                    'key' => $key,
                    'visibility' => $visibility,
                    'minimum_age' => $minimumAge,
                    'inactivity_timeout_seconds' => $inactivityTimeoutSeconds,
                    'guest_access' => $guestAccess,
                ],
                $ipAddress,
            );
            $this->roomList->room($roomId, $visibility === 'public', $actor->id);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack();
            if ($exception->getCode() === '23505') {
                throw new ApiException(409, 'room_key_taken', 'That room key is already in use.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->get($actor, $roomId);
    }

    public function join(AuthenticatedUser $actor, int $roomId, string $ipAddress): Room
    {
        $room = $this->requireRoom($actor, $roomId);
        if ($room->isMember()) {
            return $room;
        }

        if (
            $room->visibility === 'private'
            && !$room->invited
            && !RoomAuthorization::canModerateAnyRoom($actor)
        ) {
            throw new ApiException(403, 'invitation_required', 'This private room requires an invitation.');
        }

        $this->requireMinimumAge($actor, $room);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_members (room_id, user_id, role)
VALUES (:room_id, :user_id, 'member')
ON CONFLICT (room_id, user_id) DO NOTHING
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room join.');
            }
            $statement->execute(['room_id' => $roomId, 'user_id' => $actor->id]);
            if ($statement->rowCount() === 1) {
                // Only a membership created now starts reading here; a repeated join changes nothing.
                (new RoomReadService($this->pdo))->startAt($actor->id, $roomId);
            }

            $deleteInvitation = $this->pdo->prepare(
                'DELETE FROM room_invitations WHERE room_id = :room_id AND user_id = :user_id',
            );
            if ($deleteInvitation === false) {
                throw new RuntimeException('Unable to prepare invitation consumption.');
            }
            $deleteInvitation->execute(['room_id' => $roomId, 'user_id' => $actor->id]);

            $this->audit->log(
                $actor->id,
                'room.join',
                'room',
                (string) $roomId,
                [],
                $ipAddress,
            );
            $this->roomList->user($actor->id, $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->get($actor, $roomId);
    }

    public function leave(AuthenticatedUser $actor, int $roomId, string $ipAddress): void
    {
        $room = $this->requireRoom($actor, $roomId);
        if ($room->memberRole === null) {
            throw new ApiException(409, 'not_a_member', 'You are not a member of this room.');
        }
        if ($room->memberRole === 'owner') {
            throw new ApiException(409, 'owner_cannot_leave', 'The room owner cannot leave the room.');
        }

        // The role check and the delete are one statement, so a concurrent ownership transfer
        // to this member can never leave the room without an owner (#273).
        $statement = $this->pdo->prepare(
            "DELETE FROM room_members WHERE room_id = :room_id AND user_id = :user_id AND role <> 'owner'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room leave.');
        }
        $statement->execute(['room_id' => $roomId, 'user_id' => $actor->id]);
        if ($statement->rowCount() !== 1) {
            if ($this->rooms->membershipRole($roomId, $actor->id) === 'owner') {
                throw new ApiException(409, 'owner_cannot_leave', 'The room owner cannot leave the room.');
            }
            throw new ApiException(409, 'not_a_member', 'You are not a member of this room.');
        }
        $this->audit->log($actor->id, 'room.leave', 'room', (string) $roomId, [], $ipAddress);
        $this->roomList->user($actor->id, $actor->id);
    }

    public function update(
        AuthenticatedUser $actor,
        int $roomId,
        string $nameInput,
        string $infoInput,
        string $visibility,
        int $minimumAge,
        int $inactivityTimeoutSeconds,
        string $ipAddress,
        ?string $guestAccess = null,
    ): Room {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);
        $name = $this->validateName($nameInput);
        $info = $this->validateInfo($infoInput);
        $visibility = $this->validateVisibility($visibility);
        $minimumAge = $this->validateMinimumAge($minimumAge);
        $inactivityTimeoutSeconds = $this->validateInactivityTimeout($inactivityTimeoutSeconds);
        // Left out, guest access stays as it was, unless the room no longer qualifies.
        $guestAccess = $guestAccess === null
            ? ($this->guestsQualify($visibility, $minimumAge) ? $room->guestAccess : 'none')
            : $this->validateGuestAccess($guestAccess, $visibility, $minimumAge);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE rooms
SET name = :name,
    info_line = :info_line,
    visibility = :visibility,
    minimum_age = :minimum_age,
    inactivity_timeout_seconds = :inactivity_timeout_seconds,
    guest_access = :guest_access,
    updated_at = NOW()
WHERE id = :room_id AND deleted_at IS NULL
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room update.');
            }
            $statement->execute([
                'name' => $name,
                'info_line' => $info,
                'visibility' => $visibility,
                'minimum_age' => $minimumAge,
                'inactivity_timeout_seconds' => $inactivityTimeoutSeconds,
                'guest_access' => $guestAccess,
                'room_id' => $roomId,
            ]);
            if ($guestAccess === 'none' && $room->guestAccess !== 'none') {
                $this->removeGuests($roomId, $actor->id);
            }

            $this->audit->log(
                $actor->id,
                'room.update',
                'room',
                (string) $roomId,
                [
                    'visibility' => $visibility,
                    'minimum_age' => $minimumAge,
                    'inactivity_timeout_seconds' => $inactivityTimeoutSeconds,
                    'guest_access' => $guestAccess,
                ],
                $ipAddress,
            );
            $this->roomList->room($roomId, $room->visibility === 'public' || $visibility === 'public', $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->get($actor, $roomId);
    }

    public function invite(
        AuthenticatedUser $actor,
        int $roomId,
        int $targetUserId,
        string $ipAddress,
    ): void {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);
        if (!$this->rooms->userExists($targetUserId)) {
            throw new ApiException(404, 'user_not_found', 'Target user not found.');
        }
        if ($this->rooms->userIsMember($roomId, $targetUserId)) {
            throw new ApiException(409, 'already_a_member', 'The target user is already a room member.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_invitations (room_id, user_id, invited_by)
VALUES (:room_id, :user_id, :invited_by)
ON CONFLICT (room_id, user_id)
DO UPDATE SET invited_by = EXCLUDED.invited_by, created_at = NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room invitation.');
        }
        $statement->execute([
            'room_id' => $roomId,
            'user_id' => $targetUserId,
            'invited_by' => $actor->id,
        ]);

        $this->audit->log(
            $actor->id,
            'room.invite',
            'room',
            (string) $roomId,
            ['target_user_id' => $targetUserId],
            $ipAddress,
        );
        $this->roomList->user($targetUserId, $actor->id);
    }

    public function setRole(
        AuthenticatedUser $actor,
        int $roomId,
        int $targetUserId,
        string $role,
        string $ipAddress,
    ): void {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);
        if (!in_array($role, ['member', 'moderator'], true)) {
            throw new ApiException(400, 'invalid_room_role', 'Room role must be member or moderator.');
        }

        $currentRole = $this->rooms->membershipRole($roomId, $targetUserId);
        if ($currentRole === null) {
            throw new ApiException(404, 'membership_not_found', 'Target user is not a room member.');
        }
        if ($currentRole === 'owner') {
            throw new ApiException(409, 'owner_role_immutable', 'Room ownership cannot be changed by this endpoint.');
        }
        if ($role === 'moderator' && !$this->rooms->userExists($targetUserId)) {
            throw new ApiException(409, 'guest_cannot_moderate', 'Guests cannot become room moderators.');
        }

        // Never the owner row, checked in the same statement as the write (#273).
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE room_members
SET role = :role
WHERE room_id = :room_id AND user_id = :user_id AND role <> 'owner'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-role update.');
        }
        $statement->execute(['role' => $role, 'room_id' => $roomId, 'user_id' => $targetUserId]);
        if ($statement->rowCount() !== 1) {
            if ($this->rooms->membershipRole($roomId, $targetUserId) === 'owner') {
                throw new ApiException(409, 'owner_role_immutable', 'Room ownership cannot be changed by this endpoint.');
            }
            throw new ApiException(404, 'membership_not_found', 'Target user is not a room member.');
        }

        $this->audit->log(
            $actor->id,
            'room.role_changed',
            'room',
            (string) $roomId,
            ['target_user_id' => $targetUserId, 'role' => $role],
            $ipAddress,
        );
        $this->roomList->user($targetUserId, $actor->id);
    }

    /**
     * Hands a room to another member (#273). Allowed for the roles that manage every room
     * and for the current owner. The new owner must be an active, unbanned, registered member;
     * an administrator may also take over a room they are not in (their membership is created
     * here, under the room's minimum age). The previous owner becomes moderator, or member if
     * their account is closing or closed. Replacing an active owner needs a global rank at least
     * as high as theirs. Step-up is checked by the endpoint.
     *
     * Locks, in order (ADR 0007: accounts first, in ID order): the target's account FOR NO KEY
     * UPDATE (a ban or closure waits) and the previous owner's FOR KEY SHARE, then the room FOR
     * NO KEY UPDATE (name and minimum age are read from that row), then both memberships in
     * user order, re-verifying the owner read before the locks. The one-owner index (0058) makes
     * a second owner impossible; leave, setRole and removeMember refuse the owner row in the same
     * statement as their write.
     */
    public function transferOwnership(
        AuthenticatedUser $actor,
        int $roomId,
        int $targetUserId,
        string $ipAddress,
    ): Room {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);
        if ($targetUserId < 1) {
            throw new ApiException(400, 'validation_error', 'target_user_id must be positive.');
        }

        $this->pdo->beginTransaction();
        try {
            $ownerRow = $this->lockedRow(
                "SELECT user_id FROM room_members WHERE room_id = :room_id AND role = 'owner'",
                ['room_id' => $roomId],
            );
            $expectedOwner = $ownerRow === null ? null : (int) $ownerRow['user_id'];

            $accounts = [];
            $accountIds = array_values(array_unique(array_filter([$targetUserId, $expectedOwner], static fn (?int $id): bool => $id !== null)));
            sort($accountIds);
            foreach ($accountIds as $accountId) {
                $accounts[$accountId] = $this->lockedRow(
                    $accountId === $targetUserId ? <<<'SQL'
SELECT u.account_state, u.account_kind,
       EXISTS (
           SELECT 1 FROM user_bans b
           WHERE b.user_id = u.id AND b.revoked_at IS NULL
             AND b.starts_at <= NOW() AND (b.expires_at IS NULL OR b.expires_at > NOW())
       ) AS banned
FROM users u
WHERE u.id = :id
FOR NO KEY UPDATE OF u
SQL : 'SELECT account_state, account_kind, false AS banned FROM users WHERE id = :id FOR KEY SHARE',
                    ['id' => $accountId],
                );
            }

            $locked = $this->lockedRow(
                'SELECT name, minimum_age, deleted_at FROM rooms WHERE id = :id FOR NO KEY UPDATE',
                ['id' => $roomId],
            );
            if ($locked === null || $locked['deleted_at'] !== null) {
                throw new ApiException(404, 'room_not_found', 'Room not found.');
            }
            $memberships = $this->pdo->prepare(<<<'SQL'
SELECT user_id, role FROM room_members
WHERE room_id = :room_id AND (role = 'owner' OR user_id = :target_user_id)
ORDER BY user_id
FOR NO KEY UPDATE
SQL);
            if ($memberships === false) {
                throw new RuntimeException('Unable to prepare room ownership lock.');
            }
            $memberships->execute(['room_id' => $roomId, 'target_user_id' => $targetUserId]);
            $previousOwner = null;
            $targetRole = null;
            foreach ($memberships->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string) $row['role'] === 'owner') {
                    $previousOwner = (int) $row['user_id'];
                }
                if ((int) $row['user_id'] === $targetUserId) {
                    $targetRole = (string) $row['role'];
                }
            }
            if ($previousOwner !== $expectedOwner) {
                throw new ApiException(409, 'ownership_changed', 'The room changed hands meanwhile. Reload and try again.');
            }

            // Re-checked under the locks with the rule the flags use (#302): an owner whose room
            // was handed on meanwhile may no longer transfer it, and so on.
            $target = $accounts[$targetUserId] ?? null;
            $previousActive = $previousOwner !== null
                && (string) ($accounts[$previousOwner]['account_state'] ?? '') === 'active';
            $refusal = OwnershipTransferRule::refusal(
                $actor->id,
                RoomAuthorization::canManageAnyRoom($actor),
                ModerationRank::globalRankOf($actor),
                $targetUserId,
                $targetRole,
                $target !== null
                    && (string) $target['account_kind'] === 'member'
                    && (string) $target['account_state'] === 'active'
                    && !$this->databaseBoolean($target['banned']),
                $previousOwner,
                $previousActive,
                // The rank only matters for an active owner other than the actor.
                $previousActive && $previousOwner !== $actor->id ? $this->globalRank((int) $previousOwner) : 0,
                // The room's age rule, as locked.
                fn (): ?ApiException => $this->ageRefusal($actor, (int) $locked['minimum_age']),
            );
            if ($refusal !== null) {
                throw $refusal;
            }

            if ($previousOwner !== null) {
                $demote = $this->pdo->prepare(
                    "UPDATE room_members SET role = :role WHERE room_id = :room_id AND user_id = :user_id AND role = 'owner'",
                );
                if ($demote === false) {
                    throw new RuntimeException('Unable to prepare the previous owner\'s new role.');
                }
                $demote->execute([
                    'role' => $previousActive ? 'moderator' : 'member',
                    'room_id' => $roomId,
                    'user_id' => $previousOwner,
                ]);
            }

            if ($targetRole === null) {
                $promote = $this->pdo->prepare(
                    "INSERT INTO room_members (room_id, user_id, role) VALUES (:room_id, :user_id, 'owner')",
                );
            } else {
                $promote = $this->pdo->prepare(
                    "UPDATE room_members SET role = 'owner' WHERE room_id = :room_id AND user_id = :user_id",
                );
            }
            if ($promote === false) {
                throw new RuntimeException('Unable to prepare the new owner.');
            }
            try {
                $promote->execute(['room_id' => $roomId, 'user_id' => $targetUserId]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23505') {
                    // The administrator joined in another request meanwhile.
                    throw new ApiException(409, 'ownership_changed', 'The room changed meanwhile. Reload and try again.');
                }
                throw $exception;
            }
            if ($targetRole === null) {
                $invitation = $this->pdo->prepare('DELETE FROM room_invitations WHERE room_id = :room_id AND user_id = :user_id');
                if ($invitation === false) {
                    throw new RuntimeException('Unable to prepare invitation consumption.');
                }
                $invitation->execute(['room_id' => $roomId, 'user_id' => $targetUserId]);
                // A new membership starts reading at the room's newest message (#268).
                (new RoomReadService($this->pdo))->startAt($targetUserId, $roomId);
            }

            $this->audit->log(
                $actor->id,
                'room.ownership_transferred',
                'room',
                (string) $roomId,
                ['from_user_id' => $previousOwner, 'to_user_id' => $targetUserId, 'joined' => $targetRole === null],
                $ipAddress,
            );
            $context = json_encode(['room_id' => $roomId, 'room_name' => (string) $locked['name']], JSON_THROW_ON_ERROR);
            if ($targetUserId !== $actor->id) {
                $this->notify($targetUserId, 'room_ownership_received', $context);
            }
            if ($previousOwner !== null && $previousOwner !== $actor->id && $previousActive) {
                $this->notify($previousOwner, 'room_ownership_removed', $context);
            }
            $this->roomList->user($targetUserId, $actor->id);
            if ($previousOwner !== null) {
                $this->roomList->user($previousOwner, $actor->id);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->requireRoom($actor, $roomId);
    }

    private function globalRank(int $userId): int
    {
        $row = $this->lockedRow(<<<'SQL'
SELECT COALESCE(MAX(CASE role
    WHEN 'super_admin' THEN 5 WHEN 'admin' THEN 4 WHEN 'chat_admin' THEN 3 WHEN 'global_moderator' THEN 3
    ELSE 0 END), 0) AS rank
FROM user_roles WHERE user_id = :id
SQL, ['id' => $userId]);

        return $row === null ? 0 : (int) $row['rank'];
    }

    /** Why the actor can't join a room with this minimum age, or null when they can. Shared with the flags (#302). */
    public function ageRefusal(AuthenticatedUser $actor, int $minimumAge): ?ApiException
    {
        if ($minimumAge === 0) {
            return null;
        }
        $birthDate = $this->rooms->birthDateForUser($actor->id);
        if ($birthDate === null) {
            return new ApiException(403, 'birth_date_required', 'A birth date is required to join this room.');
        }
        if ((new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y < $minimumAge) {
            return new ApiException(403, 'minimum_age_not_met', 'You do not meet this room’s minimum age.');
        }

        return null;
    }

    /**
     * @param array<string, int> $parameters
     * @return ?array<string, mixed>
     */
    private function lockedRow(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare a room ownership lock.');
        }
        $statement->execute($parameters);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function notify(int $userId, string $kind, string $context): void
    {
        $statement = $this->pdo->prepare(
            // push_dispatched_at = NOW() here means "never push" for these kinds (#273): they are
            // in-app only by design. The push wake-up trigger and the materializer both skip rows
            // that already have it. Don't remove it to "fix" missing pushes.
            'INSERT INTO account_notifications (user_id, kind, context_json, push_dispatched_at) VALUES (:user_id, :kind, CAST(:context AS jsonb), NOW())',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare a room ownership notice.');
        }
        $statement->execute(['user_id' => $userId, 'kind' => $kind, 'context' => $context]);
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * Stage one of two-stage deletion: the room disappears for everyone at
     * once, but stays restorable until maintenance removes it after the
     * configured grace period (see CleanupService). Members are notified.
     */
    public function delete(AuthenticatedUser $actor, int $roomId, string $ipAddress): void
    {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE rooms SET deleted_at = NOW(), updated_at = NOW() WHERE id = :room_id AND deleted_at IS NULL',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room deletion.');
            }
            $statement->execute(['room_id' => $roomId]);
            $this->notifyMembers($room, 'room_deleted', $actor->id);
            $this->audit->log($actor->id, 'room.delete', 'room', (string) $roomId, ['name' => $room->name], $ipAddress);
            $this->roomList->room($roomId, $room->visibility === 'public', $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** Undoes a deletion that maintenance has not yet made permanent. */
    public function restore(AuthenticatedUser $actor, int $roomId, string $ipAddress): Room
    {
        $room = $this->requireDeletedRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE rooms SET deleted_at = NULL, updated_at = NOW() WHERE id = :room_id AND deleted_at IS NOT NULL',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room restoration.');
            }
            $statement->execute(['room_id' => $roomId]);
            $this->notifyMembers($room, 'room_restored', $actor->id);
            $this->audit->log($actor->id, 'room.restore', 'room', (string) $roomId, ['name' => $room->name], $ipAddress);
            $this->roomList->room($roomId, $room->visibility === 'public', $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->get($actor, $roomId);
    }

    /**
     * Deleted rooms the actor could restore: every one for global room
     * managers, their own rooms for owners. Newest deletion first.
     *
     * @return list<array{id:int, key:string, name:string, visibility:string, deleted_at:string, purge_after:?string}>
     */
    public function listDeleted(AuthenticatedUser $actor): array
    {
        $everyRoom = $actor->hasRole('super_admin') || $actor->hasRole('admin') || $actor->hasRole('chat_admin');
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.room_key, r.name, r.visibility, r.deleted_at,
       CASE WHEN s.deleted_room_grace_days = 0 THEN NULL
            ELSE r.deleted_at + make_interval(days => s.deleted_room_grace_days)
       END AS purge_after
FROM rooms r
CROSS JOIN system_settings s
WHERE s.id = 1
  AND r.deleted_at IS NOT NULL
  AND (
      CAST(:every_room AS integer) = 1
      OR EXISTS (
          SELECT 1 FROM room_members rm
          WHERE rm.room_id = r.id AND rm.user_id = :user_id AND rm.role = 'owner'
      )
  )
ORDER BY r.deleted_at DESC, r.id DESC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare deleted-room list.');
        }
        $statement->execute(['every_room' => $everyRoom ? 1 : 0, 'user_id' => $actor->id]);

        $rooms = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rooms[] = [
                'id' => (int) $row['id'],
                'key' => (string) $row['room_key'],
                'name' => (string) $row['name'],
                'visibility' => (string) $row['visibility'],
                'deleted_at' => (string) $row['deleted_at'],
                'purge_after' => $row['purge_after'] === null ? null : (string) $row['purge_after'],
            ];
        }

        return $rooms;
    }

    /** Tells every active member except the actor that the room was deleted or restored. */
    private function notifyMembers(Room $room, string $kind, int $actorUserId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json)
SELECT rm.user_id, :kind, CAST(:context AS jsonb)
FROM room_members rm
JOIN users u ON u.id = rm.user_id
WHERE rm.room_id = :room_id
  AND rm.user_id <> :actor_user_id
  AND u.account_state = 'active'
  AND u.account_kind = 'member'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room member notification.');
        }
        $statement->execute([
            'kind' => $kind,
            'context' => json_encode(['room_id' => $room->id, 'room_name' => $room->name], JSON_THROW_ON_ERROR),
            'room_id' => $room->id,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function requireDeletedRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }

        $room = $this->rooms->findDeletedForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'No deleted room with that ID is waiting to be removed.');
        }

        return $room;
    }

    private function requireRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }

        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }

        return $room;
    }

    private function requireMinimumAge(AuthenticatedUser $actor, Room $room): void
    {
        if ($room->minimumAge === 0) {
            return;
        }

        $birthDate = $this->rooms->birthDateForUser($actor->id);
        if ($birthDate === null) {
            throw new ApiException(403, 'birth_date_required', 'A birth date is required to join this room.');
        }

        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
        if ($age < $room->minimumAge) {
            throw new ApiException(403, 'minimum_age_not_met', 'You do not meet this room’s minimum age.');
        }
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 120) {
            throw new ApiException(400, 'invalid_room_name', 'Room name must contain 1-120 characters.');
        }

        return $name;
    }

    private function validateInfo(string $info): string
    {
        $info = trim($info);
        if (mb_strlen($info, 'UTF-8') > 255) {
            throw new ApiException(400, 'invalid_room_info', 'Room information must not exceed 255 characters.');
        }

        return $info;
    }

    private function validateVisibility(string $visibility): string
    {
        if (!in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            throw new ApiException(400, 'invalid_visibility', 'Visibility must be public, unlisted, or private.');
        }

        return $visibility;
    }

    private function validateMinimumAge(int $minimumAge): int
    {
        if ($minimumAge < 0 || $minimumAge > 120) {
            throw new ApiException(400, 'invalid_minimum_age', 'minimum_age must be between 0 and 120.');
        }

        return $minimumAge;
    }

    private function validateGuestAccess(string $guestAccess, string $visibility, int $minimumAge): string
    {
        if (!in_array($guestAccess, ['none', 'read', 'write'], true)) {
            throw new ApiException(400, 'invalid_guest_access', 'guest_access must be none, read, or write.');
        }
        if ($guestAccess !== 'none' && !$this->guestsQualify($visibility, $minimumAge)) {
            throw new ApiException(400, 'guest_access_scope', 'Only public rooms without a minimum age can let guests in.');
        }

        return $guestAccess;
    }

    /** Guests have no birth date and are never invited, so only open, all-ages rooms can take them. */
    private function guestsQualify(string $visibility, int $minimumAge): bool
    {
        return $visibility === 'public' && $minimumAge === 0;
    }

    /** The room closed to guests: those in it leave at once. */
    private function removeGuests(int $roomId, int $actorUserId): void
    {
        $presence = $this->pdo->prepare(<<<'SQL'
DELETE FROM room_presence p
USING users u
WHERE p.room_id = :room_id
  AND u.id = p.user_id
  AND u.account_kind = 'guest'
RETURNING p.user_id
SQL);
        $members = $this->pdo->prepare(<<<'SQL'
DELETE FROM room_members rm
USING users u
WHERE rm.room_id = :room_id
  AND u.id = rm.user_id
  AND u.account_kind = 'guest'
RETURNING rm.user_id
SQL);
        if ($presence === false || $members === false) {
            throw new RuntimeException('Unable to prepare guest removal.');
        }
        $presence->execute(['room_id' => $roomId]);
        $events = new EventRepository($this->pdo);
        foreach (array_unique(array_map('intval', $presence->fetchAll(PDO::FETCH_COLUMN))) as $userId) {
            $events->publish(
                type: 'presence_changed',
                payload: ['room_id' => $roomId, 'user_id' => $userId],
                roomId: $roomId,
                actorUserId: $userId,
            );
        }
        $members->execute(['room_id' => $roomId]);
        foreach (array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)) as $userId) {
            $this->roomList->user($userId, $actorUserId);
        }
    }

    private function validateInactivityTimeout(int $seconds): int
    {
        if ($seconds !== 0 && ($seconds < 120 || $seconds > 86400)) {
            throw new ApiException(
                400,
                'invalid_inactivity_timeout',
                'inactivity_timeout_seconds must be 0 or between 120 and 86400.',
            );
        }

        return $seconds;
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
