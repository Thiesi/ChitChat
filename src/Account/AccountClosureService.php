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

    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly RateLimiter $rateLimiter;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
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
            $row = $this->lockUser($actor->id);
            if ((string) $row['account_state'] !== 'active') {
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

            try {
                $rolesJson = json_encode($roles, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to encode account role snapshot.', 0, $exception);
            }

            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_closures (user_id, requested_at, finalizes_at, role_snapshot)
VALUES (
    :user_id,
    NOW(),
    NOW() + make_interval(days => CAST(:cooling_off_days AS double precision)),
    CAST(:role_snapshot AS jsonb)
)
RETURNING requested_at, finalizes_at
SQL);
            if ($insert === false) {
                throw new RuntimeException('Unable to prepare account-closure request.');
            }
            $insert->execute([
                'user_id' => $actor->id,
                'cooling_off_days' => self::COOLING_OFF_DAYS,
                'role_snapshot' => $rolesJson,
            ]);
            $closure = $insert->fetch();
            if (!is_array($closure)) {
                throw new RuntimeException('Account-closure request did not return lifecycle metadata.');
            }

            $update = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET account_state = 'closure_pending',
    closure_requested_at = CAST(:requested_at AS timestamptz),
    closure_finalizes_at = CAST(:finalizes_at AS timestamptz),
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :user_id
RETURNING session_version
SQL);
            if ($update === false) {
                throw new RuntimeException('Unable to prepare account-closure state update.');
            }
            $update->execute([
                'requested_at' => (string) $closure['requested_at'],
                'finalizes_at' => (string) $closure['finalizes_at'],
                'user_id' => $actor->id,
            ]);
            $sessionVersion = $update->fetchColumn();
            if ($sessionVersion === false) {
                throw new RuntimeException('Account-closure state update did not return a session version.');
            }

            $this->deleteByUser('user_roles', 'user_id', $actor->id);
            $this->deleteByUser('room_presence', 'user_id', $actor->id);
            $this->deleteByUser('sse_connections', 'user_id', $actor->id);

            $this->audit->log(
                actorUserId: $actor->id,
                action: 'account.closure_requested',
                subjectType: 'user',
                subjectId: (string) $actor->id,
                metadata: [
                    'cooling_off_days' => self::COOLING_OFF_DAYS,
                    'finalizes_at' => (string) $closure['finalizes_at'],
                ],
                ipAddress: $ipAddress,
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
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function restore(
        string $usernameInput,
        string $password,
        string $ipAddress,
    ): AuthenticatedUser {
        $canonical = Username::canonical($usernameInput);
        $this->rateLimiter->consume('account_restore', 'username:' . $canonical . '|ip:' . $ipAddress);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT id,
       username,
       password_hash,
       account_state,
       closure_finalizes_at
FROM users
WHERE username_canonical = :canonical
FOR UPDATE
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare account-restoration lookup.');
            }
            $statement->execute(['canonical' => $canonical]);
            $row = $statement->fetch();
            if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
                throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
            }

            $state = (string) $row['account_state'];
            if ($state === 'closed') {
                throw new ApiException(410, 'account_closed', 'This account has already been permanently closed.');
            }
            if ($state !== 'closure_pending') {
                throw new ApiException(409, 'account_not_pending', 'This account is not awaiting closure.');
            }

            $finalizesAt = new DateTimeImmutable((string) $row['closure_finalizes_at']);
            if ($finalizesAt <= new DateTimeImmutable()) {
                throw new ApiException(
                    410,
                    'account_restoration_expired',
                    'The account-restoration deadline has passed.',
                );
            }

            $closureStatement = $this->pdo->prepare(<<<'SQL'
SELECT id, role_snapshot::text AS role_snapshot
FROM account_closures
WHERE user_id = :user_id
  AND restored_at IS NULL
  AND finalized_at IS NULL
FOR UPDATE
SQL);
            if ($closureStatement === false) {
                throw new RuntimeException('Unable to prepare account-closure lifecycle lookup.');
            }
            $closureStatement->execute(['user_id' => (int) $row['id']]);
            $closure = $closureStatement->fetch();
            if (!is_array($closure)) {
                throw new RuntimeException('Pending account-closure lifecycle is missing.');
            }
            $roles = $this->decodeRoles((string) $closure['role_snapshot']);

            $restoreClosure = $this->pdo->prepare(
                'UPDATE account_closures SET restored_at = NOW() WHERE id = :id',
            );
            if ($restoreClosure === false) {
                throw new RuntimeException('Unable to prepare closure restoration record.');
            }
            $restoreClosure->execute(['id' => (int) $closure['id']]);

            $restoreUser = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET account_state = 'active',
    closure_requested_at = NULL,
    closure_finalizes_at = NULL,
    closed_at = NULL,
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :user_id
SQL);
            if ($restoreUser === false) {
                throw new RuntimeException('Unable to prepare account restoration.');
            }
            $restoreUser->execute(['user_id' => (int) $row['id']]);

            if ($roles !== []) {
                $insertRole = $this->pdo->prepare(
                    'INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role) ON CONFLICT DO NOTHING',
                );
                if ($insertRole === false) {
                    throw new RuntimeException('Unable to prepare restored role assignment.');
                }
                foreach ($roles as $role) {
                    $insertRole->execute(['user_id' => (int) $row['id'], 'role' => $role]);
                }
            }

            $this->audit->log(
                actorUserId: (int) $row['id'],
                action: 'account.closure_restored',
                subjectType: 'user',
                subjectId: (string) $row['id'],
                metadata: ['closure_id' => (int) $closure['id']],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            $user = $this->users->findAuthenticatedById((int) $row['id']);
            if ($user === null) {
                throw new RuntimeException('Restored account could not be reloaded.');
            }

            return $user;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
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
            $statement = $this->pdo->query(<<<'SQL'
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
            if ($statement === false) {
                throw new RuntimeException('Unable to query due account closures.');
            }

            $count = 0;
            foreach ($statement->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $userId = (int) $row['user_id'];
                $closureId = (int) $row['closure_id'];
                $oldCanonical = (string) $row['username_canonical'];
                $display = 'Closed account #' . $userId;
                $canonical = sprintf('closed-%d-%s', $userId, bin2hex(random_bytes(4)));
                $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

                $updateUser = $this->pdo->prepare(<<<'SQL'
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
SQL);
                if ($updateUser === false) {
                    throw new RuntimeException('Unable to prepare account tombstoning.');
                }
                $updateUser->execute([
                    'username' => $display,
                    'canonical' => $canonical,
                    'password_hash' => $unusablePassword,
                    'user_id' => $userId,
                ]);

                $finish = $this->pdo->prepare(
                    'UPDATE account_closures SET finalized_at = NOW() WHERE id = :id',
                );
                if ($finish === false) {
                    throw new RuntimeException('Unable to prepare closure finalization record.');
                }
                $finish->execute(['id' => $closureId]);

                $this->deleteByUser('user_roles', 'user_id', $userId);
                $this->deleteByUser('room_invitations', 'user_id', $userId);
                $this->deleteByUser('room_presence', 'user_id', $userId);
                $this->deleteByUser('sse_connections', 'user_id', $userId);

                $deleteBlocks = $this->pdo->prepare(<<<'SQL'
DELETE FROM direct_message_blocks
WHERE blocker_user_id = :user_id OR blocked_user_id = :user_id
SQL);
                if ($deleteBlocks === false) {
                    throw new RuntimeException('Unable to prepare closed-account block cleanup.');
                }
                $deleteBlocks->execute(['user_id' => $userId]);

                $deleteAttempts = $this->pdo->prepare(
                    'DELETE FROM login_attempts WHERE username_canonical = :canonical',
                );
                if ($deleteAttempts === false) {
                    throw new RuntimeException('Unable to prepare closed-account login-history cleanup.');
                }
                $deleteAttempts->execute(['canonical' => $oldCanonical]);

                $this->audit->log(
                    actorUserId: null,
                    action: 'account.closure_finalized',
                    subjectType: 'user',
                    subjectId: (string) $userId,
                    metadata: ['closure_id' => $closureId],
                    ipAddress: '127.0.0.1',
                );
                $count++;
            }

            $this->pdo->commit();

            return $count;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function lockUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id FOR UPDATE');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare account lifecycle lock.');
        }
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

    private function deleteByUser(string $table, string $column, int $userId): void
    {
        $allowed = [
            'user_roles.user_id',
            'room_invitations.user_id',
            'room_presence.user_id',
            'sse_connections.user_id',
        ];
        if (!in_array($table . '.' . $column, $allowed, true)) {
            throw new RuntimeException('Unsupported account-lifecycle cleanup target.');
        }
        $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE %s = :user_id', $table, $column));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare account-lifecycle cleanup.');
        }
        $statement->execute(['user_id' => $userId]);
    }

    /** @return list<string> */
    private function decodeRoles(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored role snapshot is invalid.', 0, $exception);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Stored role snapshot is not a list.');
        }

        $roles = [];
        foreach ($value as $role) {
            if (!is_string($role) || !in_array($role, ['super_admin', 'admin', 'chat_admin', 'global_moderator'], true)) {
                throw new RuntimeException('Stored role snapshot contains an invalid role.');
            }
            $roles[] = $role;
        }

        return array_values(array_unique($roles));
    }
}
