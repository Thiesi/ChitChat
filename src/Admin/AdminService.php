<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/** @phpstan-import-type UserRow from UserDirectoryService */
final class AdminService
{
    private const ROLES = ['super_admin', 'admin', 'chat_admin', 'global_moderator'];

    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
    }

    /** @return list<UserRow> */
    public function listUsers(
        AuthenticatedUser $actor,
        string $search = '',
        int $afterId = 0,
        int $limit = 50,
    ): array {
        return (new UserDirectoryService($this->pdo))->page($actor, $search, $afterId, $limit)['users'];
    }

    /** @param list<string> $roles */
    public function setRoles(
        AuthenticatedUser $actor,
        int $targetUserId,
        array $roles,
        string $ipAddress,
    ): void {
        $this->requireUserAdministration($actor);
        if ($targetUserId < 1) {
            throw new ApiException(400, 'validation_error', 'target_user_id must be positive.');
        }
        if ($actor->id === $targetUserId) {
            throw new ApiException(400, 'self_role_change_forbidden', 'You cannot change your own global roles.');
        }

        $roles = array_values(array_unique($roles));
        sort($roles);
        foreach ($roles as $role) {
            if (!in_array($role, self::ROLES, true)) {
                throw new ApiException(400, 'invalid_role', 'Unsupported global role.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($lock === false || $lock->fetchColumn() === false) {
                throw new RuntimeException('Unable to serialize global role changes.');
            }

            $currentActor = $this->users->findAuthenticatedById($actor->id);
            if (
                $currentActor === null
                || $currentActor->sessionVersion !== $actor->sessionVersion
                || !$currentActor->canManageUsers()
                || $this->users->activeBan($actor->id) !== null
            ) {
                throw new ApiException(401, 'authentication_required', 'Your administrative session is no longer valid.');
            }
            $actor = $currentActor;

            $target = $this->users->findAuthenticatedById($targetUserId);
            // A guest never holds a role.
            if ($target === null || $target->guest) {
                throw new ApiException(404, 'user_not_found', 'Target user not found.');
            }
            if (($target->hasRole('super_admin') || $target->hasRole('admin')) && !$actor->hasRole('super_admin')) {
                throw new ApiException(403, 'forbidden', 'Only a Super-Administrator may change the roles of an Administrator or another Super-Administrator.');
            }

            $changesSuperAdmin = in_array('super_admin', $roles, true) !== $target->hasRole('super_admin');
            if ($changesSuperAdmin && !$actor->hasRole('super_admin')) {
                throw new ApiException(403, 'forbidden', 'Only a Super-Administrator may grant or revoke that role.');
            }
            if ($target->hasRole('super_admin') && !in_array('super_admin', $roles, true)) {
                $count = $this->pdo->query("SELECT COUNT(*) FROM user_roles WHERE role = 'super_admin'");
                if ($count === false) {
                    throw new RuntimeException('Unable to count Super-Administrators.');
                }
                if ((int) $count->fetchColumn() <= 1) {
                    throw new ApiException(409, 'last_super_admin', 'The final Super-Administrator role cannot be removed.');
                }
            }

            $delete = $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            if ($delete === false) {
                throw new RuntimeException('Unable to prepare role replacement.');
            }
            $delete->execute(['user_id' => $targetUserId]);

            if ($roles !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role)',
                );
                if ($insert === false) {
                    throw new RuntimeException('Unable to prepare role assignment.');
                }
                foreach ($roles as $role) {
                    $insert->execute(['user_id' => $targetUserId, 'role' => $role]);
                }
            }

            $sessionVersion = $this->users->bumpSessionVersion($targetUserId);
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'admin.roles_changed',
                subjectType: 'user',
                subjectId: (string) $targetUserId,
                metadata: [
                    'username' => $target->username,
                    'old_roles' => $target->roles,
                    'new_roles' => $roles,
                ],
                ipAddress: $ipAddress,
            );
            $this->events->publish(
                type: 'forced_logout',
                payload: [
                    'action' => 'roles_changed',
                    'reason' => 'Your account roles were changed by an administrator.',
                    'session_version' => $sessionVersion,
                ],
                targetUserId: $targetUserId,
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{
     *   id:int,
     *   actor_user_id:?int,
     *   actor_username:?string,
     *   action:string,
     *   subject_type:string,
     *   subject_id:?string,
     *   metadata:array<string, mixed>,
     *   ip_address:string,
     *   created_at:string
     * }>
     */
    public function auditEntries(
        AuthenticatedUser $actor,
        ?int $beforeId = null,
        int $limit = 50,
        ?int $userId = null,
    ): array {
        $this->requireUserAdministration($actor);
        if ($userId !== null && $userId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT a.id,
       a.actor_user_id,
       actor.username AS actor_username,
       a.action,
       a.subject_type,
       a.subject_id,
       a.metadata_json::text AS metadata,
       a.ip_address,
       a.created_at
FROM audit_log a
LEFT JOIN users actor ON actor.id = a.actor_user_id
WHERE (CAST(:has_before AS integer) = 0 OR a.id < :before_id)
  AND (CAST(:has_user AS integer) = 0 OR a.actor_user_id = :actor_match
       OR (a.subject_type = 'user' AND a.subject_id = :subject_match))
ORDER BY a.id DESC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare audit-log query.');
        }
        $statement->bindValue(':has_before', $beforeId === null ? 0 : 1, PDO::PARAM_INT);
        $statement->bindValue(':before_id', $beforeId ?? PHP_INT_MAX, PDO::PARAM_INT);
        $statement->bindValue(':has_user', $userId === null ? 0 : 1, PDO::PARAM_INT);
        $statement->bindValue(':actor_match', $userId ?? 0, PDO::PARAM_INT);
        $statement->bindValue(':subject_match', (string) ($userId ?? 0));
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                'actor_username' => $row['actor_username'] === null ? null : (string) $row['actor_username'],
                'action' => (string) $row['action'],
                'subject_type' => (string) $row['subject_type'],
                'subject_id' => $row['subject_id'] === null ? null : (string) $row['subject_id'],
                'metadata' => $this->decodeMetadata((string) $row['metadata']),
                'ip_address' => (string) $row['ip_address'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }

    private function requireUserAdministration(AuthenticatedUser $actor): void
    {
        if (!$actor->canManageUsers()) {
            throw new ApiException(403, 'forbidden', 'User administration requires Administrator access.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(string $encoded): array
    {
        try {
            $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored audit metadata is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored audit metadata must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
