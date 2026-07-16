<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthService
{
    private readonly UserRepository $users;
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
    }

    public function register(string $usernameInput, string $password, string $ipAddress): AuthenticatedUser
    {
        $username = Username::display($usernameInput);
        $canonical = Username::canonical($usernameInput);
        PasswordPolicy::validate($password, $username);

        $userId = null;
        $this->pdo->beginTransaction();

        try {
            $settings = $this->pdo->query(
                'SELECT registration_enabled::int FROM system_settings WHERE id = 1 FOR UPDATE',
            );
            if ($settings === false) {
                throw new RuntimeException('Unable to lock registration settings.');
            }

            $registrationEnabled = $settings->fetchColumn();
            if ($registrationEnabled === false) {
                throw new RuntimeException('System settings are missing.');
            }

            if ((int) $registrationEnabled !== 1) {
                throw new ApiException(403, 'registration_disabled', 'Registration is currently disabled.');
            }

            $countResult = $this->pdo->query('SELECT COUNT(*) FROM users');
            if ($countResult === false) {
                throw new RuntimeException('Unable to count users.');
            }
            $isFirstUser = (int) $countResult->fetchColumn() === 0;

            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (username, username_canonical, password_hash)
VALUES (:username, :canonical, :password_hash)
RETURNING id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare user registration.');
            }

            $statement->execute([
                'username' => $username,
                'canonical' => $canonical,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $insertedId = $statement->fetchColumn();
            if ($insertedId === false) {
                throw new RuntimeException('Registration did not return a user ID.');
            }
            $userId = (int) $insertedId;

            if ($isFirstUser) {
                $roleStatement = $this->pdo->prepare(
                    "INSERT INTO user_roles (user_id, role) VALUES (:id, 'super_admin')",
                );
                if ($roleStatement === false) {
                    throw new RuntimeException('Unable to prepare initial role assignment.');
                }
                $roleStatement->execute(['id' => $userId]);
            }

            $this->audit->log(
                actorUserId: $userId,
                action: $isFirstUser ? 'auth.register_first_super_admin' : 'auth.register',
                subjectType: 'user',
                subjectId: (string) $userId,
                metadata: ['username' => $username],
                ipAddress: $ipAddress,
            );

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($exception->getCode() === '23505') {
                throw new ApiException(409, 'username_taken', 'That username is already registered.');
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if (!is_int($userId)) {
            throw new RuntimeException('Registration did not produce a valid user ID.');
        }

        $user = $this->users->findAuthenticatedById($userId);
        if ($user === null) {
            throw new RuntimeException('Registered user could not be reloaded.');
        }

        return $user;
    }

    public function login(string $usernameInput, string $password, string $ipAddress): AuthenticatedUser
    {
        $canonical = Username::canonical($usernameInput);

        if ($this->failedAttemptCount($canonical, $ipAddress) >= $this->config->loginMaxAttempts) {
            throw new ApiException(
                429,
                'login_throttled',
                sprintf('Too many failed login attempts. Try again in up to %d minutes.', $this->config->loginLockMinutes),
            );
        }

        $credentials = $this->users->findCredentialsByCanonical($canonical);
        if ($credentials === null || !password_verify($password, $credentials['password_hash'])) {
            $this->recordLoginAttempt($canonical, $ipAddress, false, 'invalid_credentials');
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }

        if ($this->users->activeBan($credentials['id']) !== null) {
            $this->recordLoginAttempt($canonical, $ipAddress, false, 'banned');
            throw new ApiException(403, 'account_banned', 'This account is banned.');
        }

        if (password_needs_rehash($credentials['password_hash'], PASSWORD_DEFAULT)) {
            $statement = $this->pdo->prepare(
                'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare password rehash.');
            }
            $statement->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $credentials['id'],
            ]);
        }

        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare last-login update.');
        }
        $statement->execute(['id' => $credentials['id']]);
        $this->recordLoginAttempt($canonical, $ipAddress, true, 'success');

        $user = $this->users->findAuthenticatedById($credentials['id']);
        if ($user === null) {
            throw new RuntimeException('Authenticated user could not be loaded.');
        }

        return $user;
    }

    public function changePassword(
        AuthenticatedUser $actor,
        string $currentPassword,
        string $newPassword,
        string $ipAddress,
    ): AuthenticatedUser {
        $credentials = $this->users->findCredentialsById($actor->id);
        if ($credentials === null) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
        }

        if (!password_verify($currentPassword, $credentials['password_hash'])) {
            throw new ApiException(403, 'invalid_current_password', 'The current password is incorrect.');
        }

        PasswordPolicy::validate($newPassword, $credentials['username']);

        $this->pdo->beginTransaction();
        try {
            $this->users->updatePassword($actor->id, password_hash($newPassword, PASSWORD_DEFAULT));
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'auth.password_changed',
                subjectType: 'user',
                subjectId: (string) $actor->id,
                metadata: [],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $user = $this->users->findAuthenticatedById($actor->id);
        if ($user === null) {
            throw new RuntimeException('User could not be reloaded after password change.');
        }

        return $user;
    }

    private function failedAttemptCount(string $canonical, string $ipAddress): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM login_attempts
WHERE successful = FALSE
  AND created_at >= NOW() - make_interval(mins => CAST(:minutes AS integer))
  AND (username_canonical = :username OR ip_address = :ip)
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare login throttle lookup.');
        }

        $statement->execute([
            'minutes' => $this->config->loginLockMinutes,
            'username' => $canonical,
            'ip' => $ipAddress,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function recordLoginAttempt(
        string $canonical,
        string $ipAddress,
        bool $successful,
        string $reason,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_attempts (username_canonical, ip_address, successful, reason)
VALUES (:username, :ip, :successful, :reason)
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare login-attempt record.');
        }

        $statement->bindValue(':username', $canonical);
        $statement->bindValue(':ip', $ipAddress);
        $statement->bindValue(':successful', $successful, PDO::PARAM_BOOL);
        $statement->bindValue(':reason', $reason);
        $statement->execute();
    }
}
