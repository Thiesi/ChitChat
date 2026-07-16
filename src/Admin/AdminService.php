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

    /**
     * @return list<array{
     *   id:int,
     *   username:string,
     *   roles:list<string>,
     *   created_at:string,
     *   last_login_at:?string,
     *   active_ban:?array{reason:string, expires_at:?string}
     * }>
     */
    public function listUsers(
        AuthenticatedUser $actor,
        string $search = '',
        int $afterId = 0,
        int $limit = 50,
    ): array {
        $this->requireUserAdministration($actor);
        $search = trim($search);
        if (mb_strlen($search, 'UTF-8') > 32) {
            throw new ApiException(400, 'validation_error', 'search must not exceed 32 characters.');
        }
        if ($search !== '' && preg_match('/\A[A-Za-z0-9_.-]+\z/D', $search) !== 1) {
            throw new ApiException(400, 'validation_error', 'search contains unsupported characters.');
        }
        if ($afterId < 0) {
            throw new ApiException(400, 'validation_error', 'after_id must not be negative.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id,
       u.username,
       u.created_at,
       u.last_login_at,
       COALESCE(
           jsonb_agg(ur.role ORDER BY ur.role) FILTER (WHERE ur.role IS NOT NULL),
           '[]'::jsonb
       )::text AS roles,
       active_ban.reason AS ban_reason,
       active_ban.expires_at AS ban_expires_at
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN LATERAL (
    SELECT ub.reason, ub.expires_at
    FROM user_bans ub
    WHERE ub.user_id = u.id
      AND ub.revoked_at IS NULL
      AND ub.starts_at <= NOW()
      AND (ub.expires_at IS NULL OR ub.expires_at > NOW())
    ORDER BY ub.starts_at DESC, ub.id DESC
    LIMIT 1
) active_ban ON TRUE
WHERE u.id > :after_id
  AND (
      CAST(:search_empty AS integer) = 1
      OR lower(u.username) LIKE :search_pattern
  )
GROUP BY u.id, active_ban.reason, active_ban.expires_at
ORDER BY u.id ASC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare administrator user list.');
        }
        $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue(':search_empty', $search === '' ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':search_pattern', strtolower($search) . '%');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $roles = $this->decodeRoles((string) $row['roles']);
            $activeBan = $row['ban_reason'] === null
                ? null
                : [
                    'reason' => (string) $row['ban_reason'],
                    'expires_at' => $row['ban_expires_at'] === null ? null : (string) $row['ban_expires_at'],
                ];
            $result[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'roles' => $roles,
                'created_at' => (string) $row['created_at'],
                'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                'active_ban' => $activeBan,
            ];
        }

        return $result;
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
            if ($target === null) {
                throw new ApiException(404, 'user_not_found', 'Target user not found.');
            }
            if ($target->hasRole('super_admin') && !$actor->hasRole('super_admin')) {
                throw new ApiException(403, 'forbidden', 'Only a Super-Administrator may manage another Super-Administrator.');
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
    ): array {
        $this->requireUserAdministration($actor);
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
WHERE CAST(:has_before AS integer) = 0 OR a.id < :before_id
ORDER BY a.id DESC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare audit-log query.');
        }
        $statement->bindValue(':has_before', $beforeId === null ? 0 : 1, PDO::PARAM_INT);
        $statement->bindValue(':before_id', $beforeId ?? PHP_INT_MAX, PDO::PARAM_INT);
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

    /** @return list<string> */
    private function decodeRoles(string $encoded): array
    {
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored role list is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored role list must be an array.');
        }

        return array_values(array_map(static fn (mixed $role): string => (string) $role, $decoded));
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
