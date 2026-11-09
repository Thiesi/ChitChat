<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use PDO;
use PDOStatement;
use RuntimeException;

final readonly class TotpRepository
{
    public function __construct(private PDO $pdo) {}

    public function credential(int $userId, string $phase, bool $lock = false): ?TotpCredential
    {
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Authenticator verification requires a transaction.');
        $row = $this->query(<<<'SQL'
SELECT id, user_id, phase, key_id, encode(ciphertext, 'hex') AS ciphertext,
       encode(iv, 'hex') AS iv, encode(tag, 'hex') AS tag, last_accepted_step,
       pending_session_version, pending_login_id, expires_at, created_at, activated_at, last_used_at
FROM totp_credentials WHERE user_id = :user AND phase = :phase
SQL . ($lock ? ' FOR UPDATE' : ''), ['user' => $userId, 'phase' => $phase])->fetch();
        if (!is_array($row)) return null;
        return new TotpCredential(
            (string) $row['id'], (int) $row['user_id'], (string) $row['phase'], (string) $row['key_id'],
            $this->binary($row['ciphertext']), $this->binary($row['iv']), $this->binary($row['tag']),
            (int) $row['last_accepted_step'],
            $row['pending_session_version'] === null ? null : (int) $row['pending_session_version'],
            $row['pending_login_id'] === null ? null : (string) $row['pending_login_id'],
            $row['expires_at'] === null ? null : (string) $row['expires_at'], (string) $row['created_at'],
            $row['activated_at'] === null ? null : (string) $row['activated_at'],
            $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
        );
    }

    /** @param array{ciphertext:string,iv:string,tag:string} $sealed */
    public function insertPending(string $id, int $userId, int $version, string $loginId, string $keyId, array $sealed): void
    {
        $this->query(<<<'SQL'
INSERT INTO totp_credentials (id, user_id, phase, key_id, ciphertext, iv, tag, pending_session_version, pending_login_id, expires_at)
VALUES (:id, :user, 'pending', :key, decode(:ciphertext, 'hex'), decode(:iv, 'hex'), decode(:tag, 'hex'),
        :version, :login, clock_timestamp() + INTERVAL '5 minutes')
SQL, ['id' => $id, 'user' => $userId, 'key' => $keyId, 'ciphertext' => bin2hex($sealed['ciphertext']),
            'iv' => bin2hex($sealed['iv']), 'tag' => bin2hex($sealed['tag']), 'version' => $version, 'login' => $loginId]);
    }

    public function delete(int $userId, string $phase): void
    {
        $this->query('DELETE FROM totp_credentials WHERE user_id = :user AND phase = :phase', ['user' => $userId, 'phase' => $phase]);
    }

    public function activate(string $id, int $step): void
    {
        $this->query(<<<'SQL'
UPDATE totp_credentials SET phase = 'active', last_accepted_step = :step,
    pending_session_version = NULL, pending_login_id = NULL, expires_at = NULL,
    activated_at = clock_timestamp(), last_used_at = clock_timestamp()
WHERE id = :id AND phase = 'pending'
SQL, ['id' => $id, 'step' => $step]);
    }

    public function accept(string $id, int $step): void
    {
        $updated = $this->query(<<<'SQL'
UPDATE totp_credentials SET last_accepted_step = :step, last_used_at = clock_timestamp()
WHERE id = :id AND last_accepted_step < :after AND phase = 'active'
SQL, ['step' => $step, 'id' => $id, 'after' => $step]);
        if ($updated->rowCount() !== 1) throw new RuntimeException('Authenticator replay fence was not locked.');
    }

    /** At most 100 expired pending enrollments per maintenance invocation. */
    public function cleanupExpired(bool $dryRun): int
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Authenticator cleanup must own its transaction.');
        $users = $this->query("SELECT user_id FROM totp_credentials WHERE phase = 'pending' AND expires_at <= clock_timestamp() ORDER BY user_id LIMIT 100", [])
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($dryRun) return count($users);
        $this->pdo->beginTransaction();
        try {
            $count = 0;
            foreach ($users as $userId) {
                // Account -> pending factor, matching enrollment/revocation and avoiding
                // a factor-first lock while confirmation owns the account.
                $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => (int) $userId])->fetchColumn();
                $count += $this->query("DELETE FROM totp_credentials WHERE user_id = :id AND phase = 'pending' AND expires_at <= clock_timestamp()",
                    ['id' => (int) $userId])->rowCount();
            }
            $this->pdo->commit();
            return $count;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    /** @param array<string,int|string> $parameters */
    private function query(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new RuntimeException('Unable to prepare authenticator operation.');
        $statement->execute($parameters);
        return $statement;
    }

    private function binary(mixed $hex): string
    {
        if (!is_string($hex) || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) throw TotpCipher::unavailable();
        $value = hex2bin($hex);
        if ($value === false) throw TotpCipher::unavailable();
        return $value;
    }
}