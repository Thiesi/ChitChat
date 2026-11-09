<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Http\ApiException;
use PDO;
use PDOStatement;
use RuntimeException;

/** All account/policy locks are acquired by the service before locking a transaction row. */
final readonly class NativeAuthRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string,mixed>|null */
    public function find(string $id, bool $lock = false): ?array
    {
        if (!NativeAuthConfiguration::validToken($id)) return null;
        $row = $this->statement('SELECT t.*, t.active::int AS active FROM native_auth_transactions t WHERE id = :id'
            . ($lock ? ' FOR UPDATE' : ''), ['id' => $id])->fetch();
        if (!is_array($row)) return null;
        if (is_resource($row['picture'])) $row['picture'] = stream_get_contents($row['picture']);
        return $row;
    }

    /** The prefix distinguishes native callbacks before any PHP session is opened.
     * @return array<string,mixed>|null
     */
    public function forProviderState(string $state): ?array
    {
        if (!str_starts_with($state, 'native.') || !NativeAuthConfiguration::validToken(substr($state, 7))) return null;
        $digest = hash('sha256', $state);
        $id = $this->statement('SELECT id FROM native_auth_transactions WHERE provider_state_hash = :hash',
            ['hash' => $digest])->fetchColumn();
        if (!is_string($id)) return null;
        $row = $this->find($id);
        return $row !== null && is_string($row['provider_state_hash'])
            && hash_equals($row['provider_state_hash'], $digest) ? $row : null;
    }
    /** @return array<string,mixed> */
    public function require(string $id, bool $lock = false): array
    {
        return $this->find($id, $lock)
            ?? throw new ApiException(404, 'native_transaction_not_found', 'This native sign-in request is unavailable.');
    }

    /** @param array<string,mixed> $values
     * @return array{expires_at:string}
     */
    public function insert(array $values): array
    {
        $statement = $this->statement(<<<'SQL'
INSERT INTO native_auth_transactions
(id, native_binding_hash, entry_hash, state_hash, return_state, code_challenge,
 client_id, redirect_uri, config_hash, provider, purpose, user_id, user_version, created_at, expires_at)
VALUES (:id, :native_binding_hash, :entry_hash, :state_hash, :return_state, :code_challenge,
 :client_id, :redirect_uri, :config_hash, :provider, :purpose, :user_id, :user_version, statement_timestamp(), statement_timestamp() + make_interval(secs => :ttl))
RETURNING expires_at
SQL, $values);
        $expires = $statement->fetchColumn();
        if (!is_string($expires)) throw new RuntimeException('Native expiry was not returned.');
        return ['expires_at' => $expires];
    }

    public function terminal(string $id, string $phase, ?string $errorCode = null): void
    {
        if (!in_array($phase, ['cancelled', 'superseded', 'expired'], true)) {
            throw new RuntimeException('Invalid native terminal state.');
        }
        $this->statement(<<<'SQL'
UPDATE native_auth_transactions SET phase = :phase, error_code = :error_code, active = FALSE, finished_at = clock_timestamp(),
 proof = NULL, picture = NULL, subject_hash = NULL, code_hash = NULL, code_expires_at = NULL,
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL,
 entry_hash = NULL, return_state = NULL
WHERE id = :id
SQL, ['phase' => $phase, 'error_code' => $errorCode ?? $phase, 'id' => $id]);
    }

    public function supersedeBinding(string $binding): void
    {
        $this->statement(<<<'SQL'
UPDATE native_auth_transactions SET phase = 'superseded', error_code = 'superseded', active = FALSE, finished_at = clock_timestamp(),
 proof = NULL, picture = NULL, subject_hash = NULL, code_hash = NULL, code_expires_at = NULL,
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL,
 entry_hash = NULL, return_state = NULL
WHERE native_binding_hash = :binding AND active
SQL, ['binding' => $binding]);
    }

    public function consume(string $id, string $outcome): void
    {
        $active = in_array($outcome, ['pending_mfa', 'pending_sign_up'], true);
        $this->statement(<<<'SQL'
UPDATE native_auth_transactions SET phase = 'consumed', error_code = NULL, outcome = :outcome,
 active = CAST(:active AS integer)::boolean, finished_at = clock_timestamp(),
 proof = NULL, picture = NULL, code_hash = NULL, code_expires_at = NULL,
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL,
 entry_hash = NULL, return_state = NULL,
 subject_hash = CASE WHEN CAST(:pending AS integer) = 1 THEN subject_hash ELSE NULL END
WHERE id = :id
SQL, ['id' => $id, 'outcome' => $outcome, 'active' => (int) $active, 'pending' => (int) $active]);
    }

    /** @param array{subject:string,auth_time:?int,started_at:int} $proof */
    public function stageProof(string $id, string $phase, ?int $userId, ?int $version, array $proof, ?string $picture): void
    {
        if ($picture !== null && $userId !== null) {
            // The caller holds the account lock; keep at most one private staged image per account.
            $this->statement(<<<'SQL'
UPDATE native_auth_transactions SET phase = 'superseded', error_code = 'superseded', active = FALSE, finished_at = clock_timestamp(),
 proof = NULL, picture = NULL, subject_hash = NULL, code_hash = NULL, code_expires_at = NULL,
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL,
 entry_hash = NULL, return_state = NULL
WHERE user_id = :user_id AND id <> :id AND active AND picture IS NOT NULL
SQL, ['id' => $id, 'user_id' => $userId]);
        }
        $statement = $this->prepare(<<<'SQL'
UPDATE native_auth_transactions SET phase = :phase, user_id = :user_id, user_version = :version,
 proof = CAST(:proof AS jsonb), subject_hash = :subject_hash, picture = :picture
WHERE id = :id
SQL);
        foreach (['id' => $id, 'phase' => $phase, 'user_id' => $userId, 'version' => $version,
            'proof' => json_encode($proof, JSON_THROW_ON_ERROR), 'subject_hash' => hash('sha256', $proof['subject'])] as $key => $value) {
            $statement->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $statement->bindValue(':picture', $picture, $picture === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @return array{native_auth_expired:int,native_auth_tombstones:int} */
    public function cleanup(bool $dryRun): array
    {
        $ttl = NativeAuthConfiguration::TOMBSTONE_TTL;
        if ($dryRun) {
            return [
                'native_auth_expired' => (int) $this->statement("SELECT COUNT(*) FROM native_auth_transactions WHERE active AND (expires_at <= clock_timestamp() OR callback_expires_at <= clock_timestamp() OR (phase = 'staged' AND code_expires_at <= clock_timestamp()))")->fetchColumn(),
                'native_auth_tombstones' => (int) $this->statement('SELECT COUNT(*) FROM native_auth_transactions WHERE NOT active AND finished_at < clock_timestamp() - make_interval(secs => :ttl)', ['ttl' => $ttl])->fetchColumn(),
            ];
        }
        $expired = $this->statement(<<<'SQL'
WITH due AS (
 SELECT id FROM native_auth_transactions
 WHERE active AND (expires_at <= clock_timestamp() OR callback_expires_at <= clock_timestamp()
                   OR (phase = 'staged' AND code_expires_at <= clock_timestamp()))
 ORDER BY expires_at, id LIMIT 1000 FOR UPDATE SKIP LOCKED
)
UPDATE native_auth_transactions t SET phase = 'expired',
 error_code = CASE WHEN code_expires_at <= clock_timestamp() AND expires_at > clock_timestamp()
                   THEN 'code_expired' ELSE 'expired' END,
 active = FALSE, finished_at = clock_timestamp(), proof = NULL, picture = NULL, subject_hash = NULL,
 code_hash = NULL, code_expires_at = NULL, bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL, entry_hash = NULL, return_state = NULL
FROM due WHERE t.id = due.id
SQL)->rowCount();
        $deleted = $this->statement(<<<'SQL'
WITH due AS (
 SELECT id FROM native_auth_transactions
 WHERE NOT active AND finished_at < clock_timestamp() - make_interval(secs => :ttl)
 ORDER BY finished_at, id LIMIT 1000 FOR UPDATE SKIP LOCKED
)
DELETE FROM native_auth_transactions t USING due WHERE t.id = due.id
SQL, ['ttl' => $ttl])->rowCount();
        return ['native_auth_expired' => $expired, 'native_auth_tombstones' => $deleted];
    }

    /** @param array<string,mixed> $parameters */
    public function statement(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new RuntimeException('Unable to prepare native sign-in storage.');
        return $statement;
    }
}
