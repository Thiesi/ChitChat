<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class RecoveryKeyService
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /** @return array{recovery_key:string,created_at:string} */
    public function generate(AuthenticatedUser $actor, string $ipAddress): array
    {
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume('recovery_key_management', 'user:' . $actor->id);
        $this->pdo->beginTransaction();
        try {
            $this->lockPolicy();
            $row = $this->lockAccount($actor->id);
            if ((int) $row['session_version'] !== $actor->sessionVersion) {
                throw new ApiException(401, 'authentication_required', 'Authentication is required.');
            }
            $user = (new UserRepository($this->pdo))->findAuthenticatedById($actor->id);
            if ($user === null || (new UserRepository($this->pdo))->activeBan($actor->id) !== null) {
                throw new ApiException(401, 'authentication_required', 'Authentication is required.');
            }
            SessionManager::requirePrivilegedStepUp($user, $this->config);
            $result = $this->issueInTransaction($actor->id);
            (new AuditLogger($this->pdo))->log($actor->id, 'auth.password_recovery_key_generated', 'user',
                (string) $actor->id, [], $ipAddress);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Used by account creation and an already verified reset; never returns stored material.
     * @return array{recovery_key:string,created_at:string}
     */
    public function issueInTransaction(int $userId): array
    {
        $this->requireTransaction();
        $this->lockAccount($userId);
        $key = PasswordRecoveryKey::generate();
        $hash = PasswordRecoveryKey::hash($key);
        if ($hash === null) {
            throw new RuntimeException('Recovery-key generation failed.');
        }
        $statement = $this->prepare(<<<'SQL'
INSERT INTO password_recovery_keys (user_id, key_hash, generation, created_at)
VALUES (:user_id, :hash, :generation, NOW())
ON CONFLICT (user_id) DO UPDATE
SET key_hash = EXCLUDED.key_hash, generation = EXCLUDED.generation, created_at = EXCLUDED.created_at
RETURNING created_at
SQL);
        $statement->execute(['user_id' => $userId, 'hash' => $hash, 'generation' => bin2hex(random_bytes(16))]);
        $createdAt = $statement->fetchColumn();
        if (!is_string($createdAt)) {
            throw new RuntimeException('Recovery-key creation returned no timestamp.');
        }
        return ['recovery_key' => $key, 'created_at' => self::timestamp($createdAt)];
    }

    public static function invalidateInTransaction(PDO $pdo, int $actorId, int $userId, string $ipAddress): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Recovery-key invalidation requires a transaction.');
        }
        $statement = $pdo->prepare('DELETE FROM password_recovery_keys WHERE user_id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare recovery-key invalidation.');
        }
        $statement->execute(['id' => $userId]);
        if ($statement->rowCount() > 0) {
            (new AuditLogger($pdo))->log($actorId, 'auth.password_recovery_key_invalidated', 'user',
                (string) $userId, ['reason' => 'admin_password_reset'], $ipAddress);
        }
    }

    public function lockPolicy(): void
    {
        $this->requireTransaction();
        $statement = $this->prepare('SELECT id FROM system_settings WHERE id = 1 FOR SHARE');
        $statement->execute();
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('System settings are missing.');
        }
    }

    /** @return array<string,mixed> */
    public function lockAccount(int $userId): array
    {
        $this->requireTransaction();
        // Stable across password/key changes, while allowing existing MFA audit FK checks.
        $statement = $this->prepare(<<<'SQL'
SELECT id, username, session_version, has_password::int AS has_password, account_kind, account_state
FROM users WHERE id = :id FOR NO KEY UPDATE
SQL);
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['account_state'] !== 'active') {
            throw new ApiException(401, 'authentication_required', 'Authentication is required.');
        }
        if ((int) $row['has_password'] !== 1 || $row['account_kind'] !== 'member') {
            throw new ApiException(409, 'password_account_required', 'This account has no password to recover.');
        }
        return $row;
    }

    public static function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Recovery-key writes require a transaction.');
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare password-recovery operation.');
        }
        return $statement;
    }
}
