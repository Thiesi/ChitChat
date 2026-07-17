<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use PDO;
use RuntimeException;

final class MfaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isEnabled(int $userId): bool
    {
        $statement = $this->prepare('SELECT (mfa_enabled_at IS NOT NULL)::int FROM users WHERE id = :id AND account_state = \'active\'');
        $statement->execute(['id' => $userId]);
        $value = $statement->fetchColumn();
        return $value !== false && (bool) $value;
    }

    public function userHandleForUpdate(int $userId): string
    {
        $statement = $this->prepare(<<<'SQL'
SELECT CASE WHEN webauthn_user_handle IS NULL THEN NULL ELSE encode(webauthn_user_handle, 'base64') END
FROM users
WHERE id = :id AND account_state = 'active'
FOR UPDATE
SQL);
        $statement->execute(['id' => $userId]);
        $encoded = $statement->fetchColumn();
        if ($encoded === false) {
            throw new RuntimeException('User not found while resolving the WebAuthn user handle.');
        }
        if ($encoded !== null) {
            $decoded = base64_decode((string) $encoded, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('Stored WebAuthn user handle is invalid.');
            }
            return $decoded;
        }
        $handle = random_bytes(32);
        $update = $this->prepare(<<<'SQL'
UPDATE users
SET webauthn_user_handle = decode(:handle, 'base64'), updated_at = NOW()
WHERE id = :id AND webauthn_user_handle IS NULL
SQL);
        $update->execute(['handle' => base64_encode($handle), 'id' => $userId]);
        return $handle;
    }

    public function userHandle(int $userId): ?string
    {
        $statement = $this->prepare(<<<'SQL'
SELECT CASE WHEN webauthn_user_handle IS NULL THEN NULL ELSE encode(webauthn_user_handle, 'base64') END
FROM users
WHERE id = :id AND account_state = 'active'
SQL);
        $statement->execute(['id' => $userId]);
        $encoded = $statement->fetchColumn();
        if ($encoded === false || $encoded === null) {
            return null;
        }
        $decoded = base64_decode((string) $encoded, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Stored WebAuthn user handle is invalid.');
        }
        return $decoded;
    }

    /** @return list<array{id:int,credential_id:string,label:string,transports:list<string>,algorithm:int,sign_count:int,backup_eligible:bool,backup_state:bool,created_at:string,last_used_at:?string}> */
    public function credentialsForUser(int $userId): array
    {
        $statement = $this->prepare(<<<'SQL'
SELECT id,
       encode(credential_id, 'base64') AS credential_id,
       label,
       transports,
       algorithm,
       sign_count,
       backup_eligible::int,
       backup_state::int,
       created_at,
       last_used_at
FROM webauthn_credentials
WHERE user_id = :user_id
ORDER BY id
SQL);
        $statement->execute(['user_id' => $userId]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $credentialId = base64_decode((string) $row['credential_id'], true);
            if ($credentialId === false) {
                throw new RuntimeException('Stored credential identifier is invalid.');
            }
            $result[] = [
                'id' => (int) $row['id'],
                'credential_id' => $credentialId,
                'label' => (string) $row['label'],
                'transports' => $this->stringList($row['transports']),
                'algorithm' => (int) $row['algorithm'],
                'sign_count' => (int) $row['sign_count'],
                'backup_eligible' => (int) $row['backup_eligible'] === 1,
                'backup_state' => (int) $row['backup_state'] === 1,
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            ];
        }
        return $result;
    }

    /** @return array{id:int,user_id:int,credential_id:string,public_key_cose:string,algorithm:int,sign_count:int,transports:list<string>,backup_eligible:bool,backup_state:bool}|null */
    public function credentialForAssertion(int $userId, string $credentialId): ?array
    {
        $statement = $this->prepare(<<<'SQL'
SELECT id,
       user_id,
       encode(credential_id, 'base64') AS credential_id,
       encode(public_key_cose, 'base64') AS public_key_cose,
       algorithm,
       sign_count,
       transports,
       backup_eligible::int,
       backup_state::int
FROM webauthn_credentials
WHERE user_id = :user_id
  AND credential_id = decode(:credential_id, 'base64')
FOR UPDATE
SQL);
        $statement->execute(['user_id' => $userId, 'credential_id' => base64_encode($credentialId)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $storedId = base64_decode((string) $row['credential_id'], true);
        $publicKey = base64_decode((string) $row['public_key_cose'], true);
        if ($storedId === false || $publicKey === false) {
            throw new RuntimeException('Stored WebAuthn credential data is invalid.');
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'credential_id' => $storedId,
            'public_key_cose' => $publicKey,
            'algorithm' => (int) $row['algorithm'],
            'sign_count' => (int) $row['sign_count'],
            'transports' => $this->stringList($row['transports']),
            'backup_eligible' => (int) $row['backup_eligible'] === 1,
            'backup_state' => (int) $row['backup_state'] === 1,
        ];
    }

    /** @param list<string> $transports */
    public function insertCredential(int $userId, string $credentialId, string $publicKeyCose, int $algorithm, int $signCount, array $transports, bool $backupEligible, bool $backupState, string $label): int
    {
        $statement = $this->prepare(<<<'SQL'
INSERT INTO webauthn_credentials (
    user_id, credential_id, public_key_cose, algorithm, sign_count,
    transports, backup_eligible, backup_state, label
)
VALUES (
    :user_id, decode(:credential_id, 'base64'), decode(:public_key_cose, 'base64'),
    :algorithm, :sign_count, CAST(:transports AS text[]),
    :backup_eligible, :backup_state, :label
)
RETURNING id
SQL);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':credential_id', base64_encode($credentialId));
        $statement->bindValue(':public_key_cose', base64_encode($publicKeyCose));
        $statement->bindValue(':algorithm', $algorithm, PDO::PARAM_INT);
        $statement->bindValue(':sign_count', $signCount, PDO::PARAM_INT);
        $statement->bindValue(':transports', $this->postgresArray($transports));
        $statement->bindValue(':backup_eligible', $backupEligible, PDO::PARAM_BOOL);
        $statement->bindValue(':backup_state', $backupState, PDO::PARAM_BOOL);
        $statement->bindValue(':label', $label);
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('WebAuthn credential insertion did not return an ID.');
        }
        return (int) $id;
    }

    public function updateAssertionState(int $credentialId, int $signCount, bool $backupEligible, bool $backupState): void
    {
        $statement = $this->prepare(<<<'SQL'
UPDATE webauthn_credentials
SET sign_count = :sign_count,
    backup_eligible = :backup_eligible,
    backup_state = :backup_state,
    last_used_at = NOW(),
    updated_at = NOW()
WHERE id = :id
SQL);
        $statement->bindValue(':sign_count', $signCount, PDO::PARAM_INT);
        $statement->bindValue(':backup_eligible', $backupEligible, PDO::PARAM_BOOL);
        $statement->bindValue(':backup_state', $backupState, PDO::PARAM_BOOL);
        $statement->bindValue(':id', $credentialId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function renameCredential(int $userId, int $credentialId, string $label): bool
    {
        $statement = $this->prepare('UPDATE webauthn_credentials SET label = :label, updated_at = NOW() WHERE id = :id AND user_id = :user_id');
        $statement->execute(['label' => $label, 'id' => $credentialId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function deleteCredential(int $userId, int $credentialId): bool
    {
        $statement = $this->prepare('DELETE FROM webauthn_credentials WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $credentialId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function credentialCount(int $userId): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    public function recoveryCodesRemaining(int $userId): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = :user_id AND used_at IS NULL');
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodeHashes(int $userId, array $hashes): void
    {
        $this->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $insert = $this->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (:user_id, decode(:code_hash, \'hex\'))');
        foreach ($hashes as $hash) {
            $insert->execute(['user_id' => $userId, 'code_hash' => $hash]);
        }
    }

    public function consumeRecoveryCode(int $userId, string $hashHex): bool
    {
        $statement = $this->prepare(<<<'SQL'
UPDATE mfa_recovery_codes
SET used_at = NOW()
WHERE id = (
    SELECT id FROM mfa_recovery_codes
    WHERE user_id = :user_id
      AND code_hash = decode(:code_hash, 'hex')
      AND used_at IS NULL
    FOR UPDATE
)
SQL);
        $statement->execute(['user_id' => $userId, 'code_hash' => $hashHex]);
        return $statement->rowCount() === 1;
    }

    public function markEnabled(int $userId): void
    {
        $statement = $this->prepare('UPDATE users SET mfa_enabled_at = COALESCE(mfa_enabled_at, NOW()), updated_at = NOW() WHERE id = :id AND account_state = \'active\'');
        $statement->execute(['id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Unable to enable MFA for the account.');
        }
    }

    public function clearForUser(int $userId): int
    {
        $this->prepare('DELETE FROM webauthn_credentials WHERE user_id = :id')->execute(['id' => $userId]);
        $this->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        $statement = $this->prepare(<<<'SQL'
UPDATE users
SET mfa_enabled_at = NULL,
    webauthn_user_handle = NULL,
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = :id AND account_state = 'active'
RETURNING session_version
SQL);
        $statement->execute(['id' => $userId]);
        $version = $statement->fetchColumn();
        if ($version === false) {
            throw new RuntimeException('Unable to disable MFA for the account.');
        }
        return (int) $version;
    }

    public function policyRequiresMfaForAdminRoles(): bool
    {
        $statement = $this->pdo->query('SELECT mfa_required_for_admin_roles::int FROM system_settings WHERE id = 1');
        if ($statement === false) {
            throw new RuntimeException('Unable to query the administrative MFA policy.');
        }
        return (int) $statement->fetchColumn() === 1;
    }

    public function userHasAdministrativeRole(int $userId): bool
    {
        $statement = $this->prepare(<<<'SQL'
SELECT (EXISTS (
    SELECT 1 FROM user_roles
    WHERE user_id = :user_id
      AND role IN ('super_admin', 'admin', 'chat_admin', 'global_moderator')
))::int
SQL);
        $statement->execute(['user_id' => $userId]);
        return (bool) $statement->fetchColumn();
    }

    public function hasCompleteMfa(int $userId): bool
    {
        $statement = $this->prepare(<<<'SQL'
SELECT (u.mfa_enabled_at IS NOT NULL
   AND EXISTS (SELECT 1 FROM webauthn_credentials wc WHERE wc.user_id = u.id))::int
FROM users u
WHERE u.id = :user_id AND u.account_state = 'active'
SQL);
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();
        return $value !== false && (bool) $value;
    }

    private function prepare(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare MFA database operation.');
        }
        return $statement;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
        }
        if (!is_string($value) || $value === '{}') {
            return [];
        }
        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }
        return array_values(array_map(
            static fn (?string $item): string => (string) $item,
            str_getcsv($inner, ',', '"', '\\'),
        ));
    }

    /** @param list<string> $values */
    private function postgresArray(array $values): string
    {
        return '{' . implode(',', array_map(
            static fn (string $value): string => '"' . addcslashes($value, "\\\"") . '"',
            $values,
        )) . '}';
    }
}
