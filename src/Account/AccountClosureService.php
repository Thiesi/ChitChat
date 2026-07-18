<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Username;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class AccountClosureService
{
    public const COOLING_OFF_DAYS = 14;
    private const ROLES = ['super_admin', 'admin', 'chat_admin', 'global_moderator'];

    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly RateLimiter $rateLimiter;

    public function __construct(
        private readonly PDO $pdo,
        Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->rateLimiter = new RateLimiter($pdo, $config->rateLimits);
    }

    /** @return array{state:string, requested_at:string, finalizes_at:string, cooling_off_days:int, session_version:int} */
    public function request(AuthenticatedUser $actor, string $ipAddress): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockGlobalAccountPolicy();
            $user = $this->userForUpdate($actor->id);
            if ((string) $user['account_state'] !== 'active') {
                throw new ApiException(409, 'account_not_active', 'This account is already in a closure lifecycle.');
            }

            $roles = $this->users->rolesForUser($actor->id);
            if (in_array('super_admin', $roles, true) && $this->activeSuperAdministratorCount() <= 1) {
                throw new ApiException(
                    409,
                    'last_super_admin',
                    'The final active Super-Administrator cannot close their account.',
                );
            }

            $insert = $this->prepare(<<<'SQL'
INSERT INTO account_closures (user_id, requested_at, finalizes_at, role_snapshot)
VALUES (
    :user_id,
    NOW(),
    NOW() + CAST(:cooling_off_days AS integer) * INTERVAL '1 day',
    CAST(:role_snapshot AS jsonb)
)
RETURNING id, requested_at, finalizes_at
SQL, 'account-closure request');
            $insert->execute([
                'user_id' => $actor->id,
                'cooling_off_days' => self::COOLING_OFF_DAYS,
                'role_snapshot' => $this->encodeRoles($roles),
            ]);
            $closure = $insert->fetch();
            if (!is_array($closure)) {
                throw new RuntimeException('Account-closure request did not return lifecycle metadata.');
            }

            $update = $this->prepare(<<<'SQL'
UPDATE users
SET account_state = 'closure_pending',
    closure_requested_at = CAST(:requested_at AS timestamptz),
    closure_finalizes_at = CAST(:finalizes_at AS timestamptz),
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :user_id
RETURNING session_version
SQL, 'account-closure state update');
            $update->execute([
                'requested_at' => (string) $closure['requested_at'],
                'finalizes_at' => (string) $closure['finalizes_at'],
                'user_id' => $actor->id,
            ]);
            $sessionVersion = $update->fetchColumn();
            if ($sessionVersion === false) {
                throw new RuntimeException('Account closure did not return a session version.');
            }

            $this->deleteForUser('user_roles', 'user_id', $actor->id);
            $this->deleteForUser('room_presence', 'user_id', $actor->id);
            $this->deleteForUser('sse_connections', 'user_id', $actor->id);

            $this->audit->log(
                $actor->id,
                'account.closure_requested',
                'user',
                (string) $actor->id,
                [
                    'closure_id' => (int) $closure['id'],
                    'cooling_off_days' => self::COOLING_OFF_DAYS,
                    'finalizes_at' => (string) $closure['finalizes_at'],
                ],
                $ipAddress,
            );
            $this->events->publish(
                type: 'forced_logout',
                payload: [
                    'action' => 'account_closure_requested',
                    'reason' => 'Account closure was requested.',
                    'session_version' => (int) $sessionVersion,
                ],
                targetUserId: $actor->id,
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
            $this->pdo->commit();

            return [
                'state' => 'closure_pending',
                'requested_at' => (string) $closure['requested_at'],
                'finalizes_at' => (string) $closure['finalizes_at'],
                'cooling_off_days' => self::COOLING_OFF_DAYS,
                'session_version' => (int) $sessionVersion,
            ];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function authenticateRestore(
        string $usernameInput,
        string $password,
        string $ipAddress,
    ): AuthenticatedUser {
        $canonical = Username::canonical($usernameInput);
        $this->rateLimiter->consume('account_restore', 'username:' . $canonical . '|ip:' . $ipAddress);

        $lookup = $this->prepare(<<<'SQL'
SELECT id, username, password_hash, session_version, account_state, closure_finalizes_at
FROM users
WHERE username_canonical = :canonical
SQL, 'account-restoration authentication');
        $lookup->execute(['canonical' => $canonical]);
        $user = $lookup->fetch();
        if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }
        if ((string) $user['account_state'] === 'closed') {
            throw new ApiException(410, 'account_closed', 'This account has already been permanently closed.');
        }
        if ((string) $user['account_state'] !== 'closure_pending') {
            throw new ApiException(409, 'account_not_pending', 'This account is not awaiting closure.');
        }
        if (new DateTimeImmutable((string) $user['closure_finalizes_at']) <= new DateTimeImmutable()) {
            throw new ApiException(410, 'account_restoration_expired', 'The account-restoration deadline has passed.');
        }
        if ($this->users->activeBan((int) $user['id']) !== null) {
            throw new ApiException(403, 'account_banned', 'This account is banned.');
        }

        return new AuthenticatedUser(
            id: (int) $user['id'],
            username: (string) $user['username'],
            roles: [],
            sessionVersion: (int) $user['session_version'],
        );
    }

    public function restore(string $usernameInput, string $password, string $ipAddress): AuthenticatedUser
    {
        $pending = $this->authenticateRestore($usernameInput, $password, $ipAddress);
        return $this->completeRestore($pending->id, $ipAddress);
    }

    public function completeRestore(int $userId, string $ipAddress): AuthenticatedUser
    {
        $this->pdo->beginTransaction();
        try {
            $mfaPolicyRequired = $this->lockGlobalAccountPolicy();
            $user = $this->userForUpdate($userId);
            if ((string) $user['account_state'] === 'closed') {
                throw new ApiException(410, 'account_closed', 'This account has already been permanently closed.');
            }
            if ((string) $user['account_state'] !== 'closure_pending') {
                throw new ApiException(409, 'account_not_pending', 'This account is not awaiting closure.');
            }
            if (new DateTimeImmutable((string) $user['closure_finalizes_at']) <= new DateTimeImmutable()) {
                throw new ApiException(410, 'account_restoration_expired', 'The account-restoration deadline has passed.');
            }

            $closureLookup = $this->prepare(<<<'SQL'
SELECT id, role_snapshot::text AS role_snapshot
FROM account_closures
WHERE user_id = :user_id
  AND restored_at IS NULL
  AND finalized_at IS NULL
FOR UPDATE
SQL, 'pending account-closure lookup');
            $closureLookup->execute(['user_id' => $userId]);
            $closure = $closureLookup->fetch();
            if (!is_array($closure)) {
                throw new RuntimeException('Pending account-closure lifecycle is missing.');
            }

            $snapshotRoles = $this->decodeRoles((string) $closure['role_snapshot']);
            $restoredRoles = $snapshotRoles;
            $withheldRoles = [];
            if ($mfaPolicyRequired && !$this->hasPasskeyMfa($userId)) {
                $restoredRoles = [];
                $withheldRoles = $snapshotRoles;
            }

            $this->execute(
                'UPDATE account_closures SET restored_at = NOW() WHERE id = :id',
                ['id' => (int) $closure['id']],
                'closure restoration record',
            );
            $this->execute(<<<'SQL'
UPDATE users
SET account_state = 'active',
    closure_requested_at = NULL,
    closure_finalizes_at = NULL,
    closed_at = NULL,
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :user_id
SQL, ['user_id' => $userId], 'account restoration');

            $insertRole = $this->prepare(
                'INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role) ON CONFLICT DO NOTHING',
                'restored role assignment',
            );
            foreach ($restoredRoles as $role) {
                $insertRole->execute(['user_id' => $userId, 'role' => $role]);
            }

            $this->audit->log(
                $userId,
                'account.closure_restored',
                'user',
                (string) $userId,
                [
                    'closure_id' => (int) $closure['id'],
                    'restored_roles' => $restoredRoles,
                    'withheld_roles' => $withheldRoles,
                ],
                $ipAddress,
            );
            $this->pdo->commit();

            $restored = $this->users->findAuthenticatedById($userId);
            if ($restored === null) {
                throw new RuntimeException('Restored account could not be reloaded.');
            }

            return $restored;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function dueCount(): int
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM account_closures
WHERE restored_at IS NULL
  AND finalized_at IS NULL
  AND finalizes_at <= NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to count due account closures.');
        }

        return (int) $statement->fetchColumn();
    }

    public function finalizeDue(): int
    {
        $this->pdo->beginTransaction();
        try {
            $due = $this->pdo->query(<<<'SQL'
SELECT ac.id AS closure_id, ac.user_id, u.username_canonical
FROM account_closures ac
JOIN users u ON u.id = ac.user_id
WHERE ac.restored_at IS NULL
  AND ac.finalized_at IS NULL
  AND ac.finalizes_at <= NOW()
  AND u.account_state = 'closure_pending'
ORDER BY ac.id
FOR UPDATE OF ac, u
SQL);
            if ($due === false) {
                throw new RuntimeException('Unable to query due account closures.');
            }

            $count = 0;
            foreach ($due->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->finalizeOne(
                    (int) $row['closure_id'],
                    (int) $row['user_id'],
                    (string) $row['username_canonical'],
                );
                $count++;
            }
            $this->pdo->commit();

            return $count;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function finalizeOne(int $closureId, int $userId, string $oldCanonical): void
    {
        $this->execute(<<<'SQL'
UPDATE users
SET username = :username,
    username_canonical = :canonical,
    password_hash = :password_hash,
    birth_date = NULL,
    last_login_at = NULL,
    account_state = 'closed',
    closed_at = NOW(),
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :user_id
SQL, [
            'username' => 'Closed account #' . $userId,
            'canonical' => sprintf('closed-%d-%s', $userId, bin2hex(random_bytes(4))),
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'user_id' => $userId,
        ], 'account tombstoning');
        $this->execute(
            'UPDATE account_closures SET finalized_at = NOW() WHERE id = :id',
            ['id' => $closureId],
            'closure finalization record',
        );

        $this->deleteForUser('user_roles', 'user_id', $userId);
        $this->deleteForUser('room_invitations', 'user_id', $userId);
        $this->deleteForUser('room_presence', 'user_id', $userId);
        $this->deleteForUser('sse_connections', 'user_id', $userId);
        $this->execute(
            'DELETE FROM direct_message_blocks WHERE blocker_user_id = :id OR blocked_user_id = :id',
            ['id' => $userId],
            'closed-account block cleanup',
        );
        $this->execute(
            'DELETE FROM login_attempts WHERE username_canonical = :canonical',
            ['canonical' => $oldCanonical],
            'closed-account login-history cleanup',
        );
        $this->audit->log(
            null,
            'account.closure_finalized',
            'user',
            (string) $userId,
            ['closure_id' => $closureId],
            '127.0.0.1',
        );
    }

    private function lockGlobalAccountPolicy(): bool
    {
        $statement = $this->pdo->query(
            'SELECT mfa_required_for_admin_roles::int FROM system_settings WHERE id = 1 FOR UPDATE',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to serialize account closure with global role changes.');
        }
        $required = $statement->fetchColumn();
        if ($required === false) {
            throw new RuntimeException('System settings are missing.');
        }
        return (int) $required === 1;
    }

    private function hasPasskeyMfa(int $userId): bool
    {
        $statement = $this->prepare(<<<'SQL'
SELECT (u.mfa_enabled_at IS NOT NULL
   AND EXISTS (SELECT 1 FROM webauthn_credentials wc WHERE wc.user_id = u.id))::int
FROM users u
WHERE u.id = :user_id
SQL, 'restored-role MFA validation');
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();
        return $value !== false && (bool) $value;
    }

    /** @return array<string, mixed> */
    private function userForUpdate(int $userId): array
    {
        $statement = $this->prepare('SELECT * FROM users WHERE id = :id FOR UPDATE', 'account lifecycle lock');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
        }

        return $row;
    }

    private function activeSuperAdministratorCount(): int
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
WHERE ur.role = 'super_admin'
  AND u.account_state = 'active'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to count active Super-Administrators.');
        }

        return (int) $statement->fetchColumn();
    }

    private function deleteForUser(string $table, string $column, int $userId): void
    {
        if (!in_array($table . '.' . $column, [
            'user_roles.user_id',
            'room_invitations.user_id',
            'room_presence.user_id',
            'sse_connections.user_id',
        ], true)) {
            throw new RuntimeException('Unsupported account-lifecycle cleanup target.');
        }
        $this->execute(
            sprintf('DELETE FROM %s WHERE %s = :user_id', $table, $column),
            ['user_id' => $userId],
            'account-lifecycle cleanup',
        );
    }

    /** @param list<string> $roles */
    private function encodeRoles(array $roles): string
    {
        try {
            return json_encode($roles, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode account role snapshot.', 0, $exception);
        }
    }

    /** @return list<string> */
    private function decodeRoles(string $json): array
    {
        try {
            $roles = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored role snapshot is invalid.', 0, $exception);
        }
        if (!is_array($roles) || !array_is_list($roles)) {
            throw new RuntimeException('Stored role snapshot is not a list.');
        }

        $result = [];
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                throw new RuntimeException('Stored role snapshot contains an invalid role.');
            }
            $result[] = $role;
        }

        return array_values(array_unique($result));
    }

    /** @param array<string, int|string> $parameters */
    private function execute(string $sql, array $parameters, string $operation): void
    {
        $statement = $this->prepare($sql, $operation);
        $statement->execute($parameters);
    }

    private function prepare(string $sql, string $operation): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ' . $operation . '.');
        }

        return $statement;
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
