<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Http\ApiException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** Registered logins, not online presence. Account locks always precede login locks. */
final readonly class LoginSessionRepository
{
    public const MAXIMUM_SESSIONS = 50;

    public function __construct(private PDO $pdo) {}

    /** Call only after successful full proof; an existing outer transaction retains ownership. */
    public function issue(AuthenticatedUser $user, ?string $userAgent = null): IssuedLogin
    {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $old = SessionManager::loginBinding();
            $accounts = array_unique([$user->id, ...($old === null ? [] : [$old['user_id']])]);
            sort($accounts, SORT_NUMERIC);
            foreach ($accounts as $id) $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => $id]);
            $this->assertAccount($user);
            $this->query('SELECT id FROM login_sessions WHERE user_id = :user ORDER BY id FOR UPDATE', ['user' => $user->id])->fetchAll();
            if ($old !== null) {
                $this->retireBinding($old['user_id'], $old['id'], $old['hash']);
            }
            $rows = $this->query(<<<'SQL'
SELECT id FROM login_sessions WHERE user_id = :user AND session_version = :version AND revoked_at IS NULL
ORDER BY last_seen_at DESC, id DESC OFFSET :keep
SQL, ['user' => $user->id, 'version' => $user->sessionVersion, 'keep' => self::MAXIMUM_SESSIONS - 1])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) $this->retire($user->id, (string) $id);
            $issued = new IssuedLogin(Base64Url::encode(random_bytes(32)), $user->id, $user->sessionVersion, bin2hex(random_bytes(32)));
            $this->query(<<<'SQL'
INSERT INTO login_sessions (id, user_id, session_version, binding_hash, client_hint)
VALUES (:id, :user, :version, :hash, :hint)
SQL, ['id' => $issued->id, 'user' => $user->id, 'version' => $user->sessionVersion,
                'hash' => hash('sha256', $issued->token), 'hint' => self::clientHint($userAgent)]);
            if ($own) $this->pdo->commit();
            return $issued;
        } catch (Throwable $exception) {
            if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public static function clientHint(?string $userAgent): string
    {
        $ua = substr($userAgent ?? '', 0, 512);
        $client = match (true) {
            str_contains($ua, 'ChitChat-Mobile') => 'ChitChat Mobile',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown client',
        };
        $platform = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh'), str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
        return $client . ($platform === null ? '' : ' on ' . $platform);
    }

    /** Null means active; a revoked reason is disclosed only with both holder proofs. */
    public function invalidReason(int $userId, int $version, string $id, string $hash, bool $touch = false): ?string
    {
        $row = $this->query(<<<'SQL'
SELECT revoked_at FROM login_sessions
WHERE id = :id AND user_id = :user AND session_version = :version AND binding_hash = :hash
SQL, ['id' => $id, 'user' => $userId, 'version' => $version, 'hash' => $hash])->fetch();
        if (!is_array($row)) return 'authentication_required';
        if ($row['revoked_at'] !== null) return 'session_revoked';
        if ($touch) {
            $this->query(<<<'SQL'
UPDATE login_sessions SET last_seen_at = clock_timestamp()
WHERE id = :id AND revoked_at IS NULL AND last_seen_at < clock_timestamp() - interval '5 minutes'
SQL, ['id' => $id]);
        }
        return null;
    }

    public function requireBound(AuthenticatedUser $user, string $hash): void
    {
        $exists = $this->query(<<<'SQL'
SELECT 1 FROM login_sessions
WHERE user_id = :user AND session_version = :version AND binding_hash = :hash AND revoked_at IS NULL
SQL, ['user' => $user->id, 'version' => $user->sessionVersion, 'hash' => $hash])->fetchColumn();
        if ($exists === false) throw new ApiException(401, 'session_revoked', 'This sign-in is no longer active. Sign in again.');
    }

    /** @return list<array{id:string,current:bool,created_at:string,last_active_at:string,client_hint:string,device_label:?string}> */
    public function list(AuthenticatedUser $actor, string $currentId): array
    {
        $rows = $this->query(<<<'SQL'
SELECT l.id, date_trunc('minute', l.created_at) AS created_at,
       date_trunc('minute', l.last_seen_at) AS last_active_at, l.client_hint,
       (SELECT s.device_label FROM push_subscriptions s
        WHERE s.user_id = l.user_id AND s.session_version = l.session_version AND s.session_binding_hash = l.binding_hash
          AND s.device_label IS NOT NULL
        ORDER BY s.last_used_at DESC NULLS LAST, s.id DESC LIMIT 1) AS device_label
FROM login_sessions l
WHERE l.user_id = :user AND l.session_version = :version AND l.revoked_at IS NULL
ORDER BY (l.id = :current) DESC, l.last_seen_at DESC, l.id DESC
SQL, ['user' => $actor->id, 'version' => $actor->sessionVersion, 'current' => $currentId])->fetchAll();
        return array_values(array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'current' => $row['id'] === $currentId,
            'created_at' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            'last_active_at' => (new \DateTimeImmutable((string) $row['last_active_at']))->format(DATE_ATOM),
            'client_hint' => (string) $row['client_hint'],
            'device_label' => $row['device_label'] === null ? null : (string) $row['device_label'],
        ], $rows));
    }

    /** Null target revokes all others; the current login's ID and token remain unchanged. */
    public function revoke(AuthenticatedUser $actor, string $currentId, string $hash, ?string $target, string $ip): int
    {
        if ($target !== null && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $target) !== 1) {
            throw new ApiException(400, 'validation_error', 'Provide a valid session id.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => $actor->id]);
            $this->assertAccount($actor);
            $this->query('SELECT id FROM login_sessions WHERE user_id = :user ORDER BY id FOR UPDATE', ['user' => $actor->id])->fetchAll();
            $reason = $this->invalidReason($actor->id, $actor->sessionVersion, $currentId, $hash);
            if ($reason !== null) throw new ApiException(401, $reason, 'Sign in again before managing sessions.');
            if ($target === $currentId) throw new ApiException(409, 'current_session', 'Use Sign out to end this sign-in.');
            $rows = $this->query('SELECT id, revoked_at FROM login_sessions WHERE user_id = :user ORDER BY id',
                ['user' => $actor->id])->fetchAll();
            $found = $target === null;
            $count = 0;
            foreach ($rows as $row) {
                if ($row['id'] === $currentId || ($target !== null && $row['id'] !== $target)) continue;
                $found = true;
                if ($row['revoked_at'] !== null) continue;
                $this->retire($actor->id, (string) $row['id']);
                $count++;
            }
            if (!$found) throw new ApiException(404, 'session_not_found', 'This session was not found.');
            (new AuditLogger($this->pdo))->log($actor->id, 'auth.sessions_revoked', 'user', (string) $actor->id,
                ['count' => $count, 'session_id' => $target], $ip);
            $this->pdo->commit();
            return $count;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** Explicit logout: keep a tombstone, remove its subscriptions atomically. */
    public function logout(int $userId, string $id, string $hash): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => $userId]);
            $this->retireBinding($userId, $id, $hash);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function prune(): int
    {
        return $this->query("DELETE FROM login_sessions WHERE revoked_at < clock_timestamp() - interval '7 days'")->rowCount();
    }

    private function assertAccount(AuthenticatedUser $user): void
    {
        $row = $this->query('SELECT session_version, account_state FROM users WHERE id = :id', ['id' => $user->id])->fetch();
        if (!is_array($row) || $row['account_state'] !== 'active' || (int) $row['session_version'] !== $user->sessionVersion
            || (new UserRepository($this->pdo))->activeBan($user->id) !== null) {
            throw new ApiException(401, 'authentication_required', 'Authentication is required.');
        }
    }

    private function retireBinding(int $userId, string $id, string $hash): void
    {
        $row = $this->query('SELECT id FROM login_sessions WHERE id = :id AND user_id = :user AND binding_hash = :hash FOR UPDATE',
            ['id' => $id, 'user' => $userId, 'hash' => $hash])->fetchColumn();
        if ($row !== false) $this->retire($userId, $id);
    }

    private function retire(int $userId, string $id): void
    {
        $row = $this->query(<<<'SQL'
UPDATE login_sessions SET revoked_at = COALESCE(revoked_at, clock_timestamp())
WHERE id = :id AND user_id = :user RETURNING binding_hash
SQL, ['id' => $id, 'user' => $userId])->fetchColumn();
        if (is_string($row)) $this->query('DELETE FROM push_subscriptions WHERE user_id = :user AND session_binding_hash = :hash',
            ['user' => $userId, 'hash' => $row]);
    }

    /** @param array<string,mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new RuntimeException('Unable to prepare login-session operation.');
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $statement->execute();
        return $statement;
    }
}
