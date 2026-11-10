<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Config;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** One bounded batch per invocation. Dry run is the default, with no writes/audits. */
final readonly class TotpKeyRotation
{
    public function __construct(private PDO $pdo, private Config $config) {}

    /** IDs and counts are operator-CLI data only, never account status.
     * @return list<array{key_id:string,active:int,pending:int}>
     */
    public function references(): array
    {
        $rows = $this->query(<<<'SQL'
SELECT key_id, COUNT(*) FILTER (WHERE phase = 'active') AS active,
       COUNT(*) FILTER (WHERE phase = 'pending') AS pending
FROM totp_credentials GROUP BY key_id ORDER BY key_id
SQL)->fetchAll();
        return array_values(array_map(static fn (array $row): array => [
            'key_id' => (string) $row['key_id'], 'active' => (int) $row['active'], 'pending' => (int) $row['pending'],
        ], $rows));
    }

    /** Up to two credentials per selected account. New writes already use the active server key.
     * @return array{dry_run:bool,accounts:int,credentials:int,rewrapped:int,next_after_user_id:int,more:bool}
     */
    public function batch(int $limit = 100, int $afterUserId = 0, bool $apply = false): array
    {
        if ($limit < 1 || $limit > 100 || $afterUserId < 0) throw new InvalidArgumentException('Use a limit of 1-100 and a nonnegative account cursor.');
        if (!$this->config->totpKeyRing->configured()) throw new RuntimeException('Configure the server write key before rotating authenticator secrets.');
        if ($this->pdo->inTransaction()) throw new RuntimeException('Authenticator rotation must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            if ($apply) {
                // Same global policy -> account -> key -> factor order as enrollment.
                $this->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
            } else {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $users = $this->query(<<<'SQL'
SELECT DISTINCT user_id FROM totp_credentials
WHERE user_id > :after AND key_id <> :key ORDER BY user_id LIMIT :limit
SQL, ['after' => $afterUserId, 'key' => $this->config->totpKeyRing->activeKeyId, 'limit' => $limit + 1])->fetchAll(PDO::FETCH_COLUMN);
            $more = count($users) > $limit;
            $users = array_slice($users, 0, $limit);
            if ($apply) {
                foreach ($users as $user) {
                    $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => (int) $user])->fetchColumn();
                }
            }
            $keys = new TotpKeyStore($this->pdo, $this->config);
            $target = $keys->writeKey();
            $credentials = new TotpRepository($this->pdo);
            $count = 0;
            $rewrapped = 0;
            foreach ($users as $userId) {
                foreach (['active', 'pending'] as $phase) {
                    $credential = $credentials->credential((int) $userId, $phase, $apply);
                    if ($credential === null || $credential->keyId === $target['id']) continue;
                    ++$count;
                    $seed = $credential->decrypt($keys);
                    if (!$apply) continue;
                    $sealed = TotpCipher::encrypt($seed, $target['key'], $credential->userId, $credential->id, $target['id']);
                    $this->query(<<<'SQL'
UPDATE totp_credentials SET key_id = :key, ciphertext = decode(:ciphertext, 'hex'),
    iv = decode(:iv, 'hex'), tag = decode(:tag, 'hex') WHERE id = :id
SQL, ['key' => $target['id'], 'ciphertext' => bin2hex($sealed['ciphertext']),
                        'iv' => bin2hex($sealed['iv']), 'tag' => bin2hex($sealed['tag']), 'id' => $credential->id]);
                    ++$rewrapped;
                }
            }
            $this->pdo->commit();
            return ['dry_run' => !$apply, 'accounts' => count($users), 'credentials' => $count, 'rewrapped' => $rewrapped,
                'next_after_user_id' => $users === [] ? $afterUserId : (int) $users[count($users) - 1], 'more' => $more];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** Once all pending/active references are migrated to server keys, retire DB key explicitly. */
    public function retireDatabaseKey(bool $apply = false): bool
    {
        if (!$this->config->totpKeyRing->configured()) throw new RuntimeException('Configure server keys before retiring a database key.');
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
            $row = $this->query('SELECT key_id FROM totp_database_key WHERE id = 1 FOR UPDATE')->fetchColumn();
            if (!is_string($row)) { $this->pdo->rollBack(); return false; }
            $refs = $this->query('SELECT COUNT(*) FROM totp_credentials WHERE key_id = :key', ['key' => $row])->fetchColumn();
            if ((int) $refs !== 0) throw new RuntimeException('The database key still protects active or pending authenticator credentials.');
            if ($apply) $this->pdo->exec('DELETE FROM totp_database_key WHERE id = 1');
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    /** @param array<string,int|string> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new RuntimeException('Unable to prepare authenticator rotation.');
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->execute();
        return $statement;
    }
}