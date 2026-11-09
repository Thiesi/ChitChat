<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Config;
use PDO;
use RuntimeException;

/** Server keys always win writes; an enabled database key remains decrypt-only. */
final readonly class TotpKeyStore
{
    public function __construct(private PDO $pdo, private Config $config) {}

    /** Must be called inside the seed-write transaction, after its account lock.
     * @return array{id:string,key:string}
     */
    public function writeKey(): array
    {
        $this->requireTransaction();
        if ($this->config->totpKeyRing->configured()) {
            $id = $this->config->totpKeyRing->activeKeyId;
            $key = $this->config->totpKeyRing->key($id);
            if ($key === null) throw TotpCipher::unavailable();
            return ['id' => $id, 'key' => $key];
        }
        // Prevent switch-off between selecting the DB key and committing a seed.
        $row = $this->databaseKey(true);
        if ($row === null) throw TotpCipher::unavailable();
        return $row;
    }

    public function readKey(string $id): string
    {
        $key = $this->config->totpKeyRing->key($id);
        if ($key !== null) return $key;
        $database = $this->databaseKey(false);
        if ($database !== null && hash_equals($database['id'], $id)) return $database['key'];
        throw TotpCipher::unavailable();
    }

    public function available(): bool
    {
        return extension_loaded('openssl')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
            && ($this->config->totpKeyRing->configured() || $this->databaseKey(false) !== null);
    }

    /** @return 'server'|'database'|'unavailable' */
    public function mode(): string
    {
        return $this->config->totpKeyRing->configured() ? 'server'
            : ($this->databaseKey(false) !== null ? 'database' : 'unavailable');
    }

    /** @return array{id:string,key:string}|null */
    private function databaseKey(bool $lock): ?array
    {
        $statement = $this->pdo->query("SELECT key_id, encode(key_bytes, 'hex') AS key_hex FROM totp_database_key WHERE id = 1" . ($lock ? ' FOR SHARE' : ''));
        if ($statement === false) throw new RuntimeException('Unable to read authenticator key mode.');
        $row = $statement->fetch();
        if (!is_array($row)) return null;
        $key = hex2bin((string) $row['key_hex']);
        if ($key === false || strlen($key) !== 32) throw TotpCipher::unavailable();
        return ['id' => (string) $row['key_id'], 'key' => $key];
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Authenticator writes require a transaction.');
    }
}