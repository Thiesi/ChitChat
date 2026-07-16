<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class RoomService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
    }

    /** @return list<array{id:int, key:string, name:string, info_line:string, visibility:string, minimum_age:int, created_by:int, member_role:?string, invited:bool}> */
    public function list(AuthenticatedUser $actor): array
    {
        $includeAll = RoomAuthorization::canModerateAnyRoom($actor);
        return array_map(
            static fn (Room $room): array => $room->toArray(),
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
        string $ipAddress,
    ): Room {
        RoomAuthorization::requireCreate($actor);
        $key = RoomKey::normalize($keyInput);
        $name = $this->validateName($nameInput);
        $info = $this->validateInfo($infoInput);
        $visibility = $this->validateVisibility($visibility);
        $minimumAge = $this->validateMinimumAge($minimumAge);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO rooms (room_key, name, info_line, visibility, minimum_age, created_by)
VALUES (:room_key, :name, :info_line, :visibility, :minimum_age, :created_by)
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
                'created_by' => $actor->id,
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

            $this->audit->log(
                $actor->id,
                'room.create',
                'room',
                (string) $roomId,
                ['key' => $key, 'visibility' => $visibility, 'minimum_age' => $minimumAge],
                $ipAddress,
            );
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

        $statement = $this->pdo->prepare(
            'DELETE FROM room_members WHERE room_id = :room_id AND user_id = :user_id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room leave.');
        }
        $statement->execute(['room_id' => $roomId, 'user_id' => $actor->id]);
        $this->audit->log($actor->id, 'room.leave', 'room', (string) $roomId, [], $ipAddress);
    }

    public function update(
        AuthenticatedUser $actor,
        int $roomId,
        string $nameInput,
        string $infoInput,
        string $visibility,
        int $minimumAge,
        string $ipAddress,
    ): Room {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);
        $name = $this->validateName($nameInput);
        $info = $this->validateInfo($infoInput);
        $visibility = $this->validateVisibility($visibility);
        $minimumAge = $this->validateMinimumAge($minimumAge);

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE rooms
SET name = :name,
    info_line = :info_line,
    visibility = :visibility,
    minimum_age = :minimum_age,
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
            'room_id' => $roomId,
        ]);

        $this->audit->log(
            $actor->id,
            'room.update',
            'room',
            (string) $roomId,
            ['visibility' => $visibility, 'minimum_age' => $minimumAge],
            $ipAddress,
        );

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

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE room_members
SET role = :role
WHERE room_id = :room_id AND user_id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-role update.');
        }
        $statement->execute(['role' => $role, 'room_id' => $roomId, 'user_id' => $targetUserId]);

        $this->audit->log(
            $actor->id,
            'room.role_changed',
            'room',
            (string) $roomId,
            ['target_user_id' => $targetUserId, 'role' => $role],
            $ipAddress,
        );
    }

    public function delete(AuthenticatedUser $actor, int $roomId, string $ipAddress): void
    {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireManage($actor, $room);

        $statement = $this->pdo->prepare(
            'UPDATE rooms SET deleted_at = NOW(), updated_at = NOW() WHERE id = :room_id AND deleted_at IS NULL',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room deletion.');
        }
        $statement->execute(['room_id' => $roomId]);
        $this->audit->log($actor->id, 'room.delete', 'room', (string) $roomId, [], $ipAddress);
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

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
