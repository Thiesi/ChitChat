<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   id:int,
     *   username:string,
     *   password_hash:string,
     *   session_version:int,
     *   account_state:string,
     *   closure_finalizes_at:?string
     * }|null
     */
    public function findCredentialsByCanonical(string $canonical): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id,
       username,
       password_hash,
       session_version,
       account_state,
       closure_finalizes_at
FROM users
WHERE username_canonical = :username
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare user credential lookup.');
        }

        $statement->execute(['username' => $canonical]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'session_version' => (int) $row['session_version'],
            'account_state' => (string) $row['account_state'],
            'closure_finalizes_at' => $row['closure_finalizes_at'] === null
                ? null
                : (string) $row['closure_finalizes_at'],
        ];
    }

    /** @return array{id:int, username:string, password_hash:string, session_version:int}|null */
    public function findCredentialsById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, username, password_hash, session_version
FROM users
WHERE id = :id
  AND account_state = 'active'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare user lookup.');
        }

        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'session_version' => (int) $row['session_version'],
        ];
    }

    public function findAuthenticatedById(int $userId): ?AuthenticatedUser
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, username, session_version, account_kind, guest_expires_at
FROM users
WHERE id = :id
  AND account_state = 'active'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare authenticated user lookup.');
        }

        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $guest = (string) $row['account_kind'] === 'guest';

        return new AuthenticatedUser(
            id: (int) $row['id'],
            username: (string) $row['username'],
            // A guest never holds a role, whatever the table says.
            roles: $guest ? [] : $this->rolesForUser((int) $row['id']),
            sessionVersion: (int) $row['session_version'],
            guest: $guest,
            guestExpiresAt: $guest && $row['guest_expires_at'] !== null ? (string) $row['guest_expires_at'] : null,
        );
    }

    public function findClosurePendingById(int $userId): ?AuthenticatedUser
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, username, session_version
FROM users
WHERE id = :id
  AND account_state = 'closure_pending'
  AND closure_finalizes_at > NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare closure-pending user lookup.');
        }

        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new AuthenticatedUser(
            id: (int) $row['id'],
            username: (string) $row['username'],
            roles: [],
            sessionVersion: (int) $row['session_version'],
        );
    }

    /** @return list<string> */
    public function rolesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id ORDER BY role');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare role lookup.');
        }

        $statement->execute(['id' => $userId]);
        $roles = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $role): string => (string) $role, $roles));
    }

    /** @return array{id:int, reason:string, expires_at:?string}|null */
    public function activeBan(int $userId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, reason, expires_at
FROM user_bans
WHERE user_id = :id
  AND revoked_at IS NULL
  AND starts_at <= NOW()
  AND (expires_at IS NULL OR expires_at > NOW())
ORDER BY starts_at DESC
LIMIT 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ban lookup.');
        }

        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'reason' => (string) $row['reason'],
            'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
        ];
    }

    public function bumpSessionVersion(int $userId): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET session_version = session_version + 1, updated_at = NOW()
WHERE id = :id
RETURNING session_version
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare session invalidation.');
        }

        $statement->execute(['id' => $userId]);
        $version = $statement->fetchColumn();
        if ($version === false) {
            throw new RuntimeException('User not found while invalidating sessions.');
        }

        return (int) $version;
    }

    /** False for accounts created through Google or Twitch that never set a password. */
    public function hasPassword(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT has_password::int FROM users WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare password lookup.');
        }
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function updatePassword(int $userId, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET password_hash = :password_hash,
    has_password = TRUE,
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :id
  AND account_state = 'active'
RETURNING session_version
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare password update.');
        }

        $statement->execute(['id' => $userId, 'password_hash' => $passwordHash]);
        $version = $statement->fetchColumn();
        if ($version === false) {
            throw new RuntimeException('User not found while updating password.');
        }

        return (int) $version;
    }

    /**
     * Whether a guest session may continue: guest access is on, and the
     * guest has neither expired nor idled out. Marks the guest as seen, at
     * most once a minute so busy pages do not write on every request.
     */
    public function touchGuest(int $userId, int $idleSeconds): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
WITH valid AS (
    SELECT u.id, u.guest_last_seen_at
    FROM users u
    CROSS JOIN system_settings s
    WHERE u.id = :user_id
      AND s.id = 1
      AND s.guest_access_enabled
      AND u.account_kind = 'guest'
      AND u.account_state = 'active'
      AND u.guest_expires_at > NOW()
      AND u.guest_last_seen_at > NOW() - CAST(:idle_seconds AS integer) * INTERVAL '1 second'
), touched AS (
    -- A data-modifying CTE always runs, whether or not it is read.
    UPDATE users
    SET guest_last_seen_at = NOW()
    FROM valid
    WHERE users.id = valid.id
      AND valid.guest_last_seen_at < NOW() - INTERVAL '1 minute'
)
SELECT COUNT(*) FROM valid
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the guest session check.');
        }
        $statement->execute(['user_id' => $userId, 'idle_seconds' => $idleSeconds]);

        return (int) $statement->fetchColumn() === 1;
    }
}
