<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\MfaPolicyGuard;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;
use RuntimeException;
use Throwable;

final readonly class TotpKeySettings
{
    public const WARNING = 'Database-key mode stores the encryption key beside authenticator secrets. A leaked database backup exposes every authenticator secret. Use a server key ring for stronger backup protection.';

    public function __construct(private PDO $pdo, private Config $config) {}

    /** @return array{mode:string,available:bool,database_key_enabled:bool,database_key_switch_available:bool,database_key_references:int,warning:string} */
    public function status(AuthenticatedUser $actor): array
    {
        $this->requireSuperAdministrator($actor);
        $keys = new TotpKeyStore($this->pdo, $this->config);
        $database = $this->pdo->query('SELECT key_id FROM totp_database_key WHERE id = 1');
        if ($database === false) throw new RuntimeException('Unable to read authenticator key setting.');
        $id = $database->fetchColumn();
        $count = 0;
        if (is_string($id)) {
            $refs = $this->pdo->prepare('SELECT COUNT(*) FROM totp_credentials WHERE key_id = :key');
            if ($refs === false) throw new RuntimeException('Unable to read authenticator key references.');
            $refs->execute(['key' => $id]);
            $count = (int) $refs->fetchColumn();
        }
        return ['mode' => $keys->mode(), 'available' => $keys->available(),
            'database_key_enabled' => is_string($id),
            'database_key_switch_available' => !$this->config->totpKeyRing->configured(),
            'database_key_references' => $count, 'warning' => self::WARNING];
    }

    /** @return array{mode:string,available:bool,database_key_enabled:bool,database_key_switch_available:bool,database_key_references:int,warning:string} */
    public function update(AuthenticatedUser $actor, bool $enabled, string $ip): array
    {
        $this->requireSuperAdministrator($actor);
        if ($this->pdo->inTransaction()) throw new RuntimeException('Authenticator settings must own their transaction.');
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume('totp_key_management', $actor->id . '|' . $ip);
        $this->pdo->beginTransaction();
        try {
            // The exclusive installation lock also prevents the absent-key enable race.
            $policy = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($policy === false || $policy->fetchColumn() === false) throw new RuntimeException('Account policy is unavailable.');
            $fresh = (new MfaPolicyGuard($this->pdo, $this->config))->lock($actor, true);
            $this->requireSuperAdministrator($fresh);
            if ($this->config->totpKeyRing->configured()) {
                throw new ApiException(409, 'totp_server_keys_configured', 'Server keys take precedence. Use the rotation command to migrate or retire a database key.');
            }
            $query = $this->pdo->query('SELECT key_id FROM totp_database_key WHERE id = 1 FOR UPDATE');
            if ($query === false) throw new RuntimeException('Unable to lock authenticator key setting.');
            $id = $query->fetchColumn();
            $changed = false;
            if ($enabled && $id === false) {
                $insert = $this->pdo->prepare("INSERT INTO totp_database_key (id, key_id, key_bytes) VALUES (1, :id, decode(:key, 'hex'))");
                if ($insert === false) throw new RuntimeException('Unable to enable authenticator database key.');
                $insert->execute(['id' => 'db:' . bin2hex(random_bytes(16)), 'key' => bin2hex(random_bytes(32))]);
                $changed = true;
            } elseif (!$enabled && is_string($id)) {
                $refs = $this->pdo->prepare('SELECT COUNT(*) FROM totp_credentials WHERE key_id = :key');
                if ($refs === false) throw new RuntimeException('Unable to check authenticator key references.');
                $refs->execute(['key' => $id]);
                if ((int) $refs->fetchColumn() !== 0) {
                    throw new ApiException(409, 'totp_key_in_use', 'This key still protects active or pending authenticators. Migrate them with the rotation command before switching it off.');
                }
                $this->pdo->exec('DELETE FROM totp_database_key WHERE id = 1');
                $changed = true;
            }
            if ($changed) {
                (new AuditLogger($this->pdo))->log($fresh->id, 'settings.totp_database_key_updated', 'system_settings', '1',
                    ['database_key_enabled' => $enabled], $ip);
            }
            $this->pdo->commit();
            return $this->status($fresh);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if ($actor->guest || !$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'Authenticator key settings require Super-Administrator access.');
        }
    }
}