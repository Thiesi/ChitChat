<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthService
{
    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly RateLimiter $rateLimiter;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->rateLimiter = new RateLimiter($pdo, $config->rateLimits);
    }

    public function register(
        string $usernameInput,
        string $password,
        string $ipAddress,
        ?string $birthDate = null,
    ): AuthenticatedUser {
        return $this->createAccount($usernameInput, $password, $ipAddress, $birthDate, null);
    }

    /**
     * Creates an account that signs in with Google or Twitch and has no
     * password until its owner sets one.
     */
    public function registerWithProvider(
        string $usernameInput,
        string $provider,
        string $subject,
        string $ipAddress,
        ?string $birthDate = null,
    ): AuthenticatedUser {
        return $this->createAccount($usernameInput, null, $ipAddress, $birthDate, [
            'provider' => $provider,
            'subject' => $subject,
        ]);
    }

    /** @param ?array{provider:string, subject:string} $identity */
    private function createAccount(
        string $usernameInput,
        ?string $password,
        string $ipAddress,
        ?string $birthDate,
        ?array $identity,
    ): AuthenticatedUser {
        (new LockdownService($this->pdo))->assertOpen();
        $username = Username::display($usernameInput);
        $canonical = Username::canonical($usernameInput);
        $normalizedBirthDate = BirthDate::normalize($birthDate);
        if ($password !== null) {
            PasswordPolicy::validate($password, $username);
        }

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

            $countResult = $this->pdo->query("SELECT COUNT(*) FROM users WHERE account_state = 'active' AND account_kind = 'member'");
            if ($countResult === false) {
                throw new RuntimeException('Unable to count users.');
            }
            $isFirstUser = (int) $countResult->fetchColumn() === 0;

            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (username, username_canonical, password_hash, has_password, birth_date)
VALUES (:username, :canonical, :password_hash, :has_password, :birth_date)
RETURNING id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare user registration.');
            }

            $statement->execute([
                'username' => $username,
                'canonical' => $canonical,
                // Without a password the hash is random and can never match.
                'password_hash' => password_hash($password ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'has_password' => $password === null ? 'false' : 'true',
                'birth_date' => $normalizedBirthDate,
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

            if ($identity !== null) {
                $identityStatement = $this->pdo->prepare(
                    'INSERT INTO user_identities (user_id, provider, subject, last_used_at) VALUES (:user_id, :provider, :subject, NOW())',
                );
                if ($identityStatement === false) {
                    throw new RuntimeException('Unable to prepare sign-in provider connection.');
                }
                $identityStatement->execute(['user_id' => $userId] + $identity);
            }

            $this->audit->log(
                actorUserId: $userId,
                action: $isFirstUser ? 'auth.register_first_super_admin' : 'auth.register',
                subjectType: 'user',
                subjectId: (string) $userId,
                metadata: $identity === null
                    ? ['username' => $username]
                    : ['username' => $username, 'provider' => $identity['provider']],
                ipAddress: $ipAddress,
            );

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($exception->getCode() === '23505') {
                if (str_contains($exception->getMessage(), 'user_identities')) {
                    throw new ApiException(409, 'identity_taken', 'This sign-in account is already connected to another account here.');
                }
                throw new ApiException(409, 'username_taken', 'That username is already registered.');
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $user = $this->users->findAuthenticatedById($userId);
        if ($user === null) {
            throw new RuntimeException('Registered user could not be reloaded.');
        }

        return $user;
    }

    public function login(string $usernameInput, string $password, string $ipAddress): AuthenticatedUser
    {
        $user = $this->authenticatePassword($usernameInput, $password, $ipAddress);
        $this->completeLogin($user, $ipAddress);
        return $user;
    }

    public function authenticatePassword(
        string $usernameInput,
        string $password,
        string $ipAddress,
    ): AuthenticatedUser {
        $canonical = Username::canonical($usernameInput);
        $usernamePolicy = $this->config->rateLimitPolicy('login');
        $ipPolicy = $this->config->rateLimitPolicy('login_ip');

        $usernameFailures = $this->failedAttemptCountByUsername($canonical, $usernamePolicy->windowSeconds);
        $ipFailures = $this->failedAttemptCountByIp($ipAddress, $ipPolicy->windowSeconds);

        if ($usernameFailures >= $usernamePolicy->maximumAttempts || $ipFailures >= $ipPolicy->maximumAttempts) {
            $this->rateLimiter->recordDecision('login', false);
            $this->rateLimiter->recordDecision('login_ip', false);
            $windowSeconds = $usernameFailures >= $usernamePolicy->maximumAttempts
                ? $usernamePolicy->windowSeconds
                : $ipPolicy->windowSeconds;
            throw new ApiException(
                429,
                'login_throttled',
                sprintf(
                    'Too many failed login attempts. Try again in up to %d minutes.',
                    max(1, (int) ceil($windowSeconds / 60)),
                ),
            );
        }
        $this->rateLimiter->recordDecision('login', true);
        $this->rateLimiter->recordDecision('login_ip', true);

        $credentials = $this->users->findCredentialsByCanonical($canonical);
        // Always run password_verify, even for a nonexistent account, against a fixed
        // dummy hash so lookup misses and failed verifications take comparable time and
        // the response timing cannot be used to enumerate valid usernames.
        $hashToVerify = $credentials['password_hash'] ?? PasswordPolicy::DUMMY_PASSWORD_HASH;
        $verified = password_verify($password, $hashToVerify);
        if ($credentials === null || !$verified) {
            $this->recordLoginAttempt($canonical, $ipAddress, false, 'invalid_credentials');
            throw new ApiException(401, 'invalid_credentials', 'Invalid username or password.');
        }

        if ($credentials['account_state'] === 'closure_pending') {
            $this->recordLoginAttempt($canonical, $ipAddress, false, 'closure_pending');
            throw new ApiException(
                423,
                'account_closure_pending',
                'This account is scheduled for closure. Use Restore account before the cooling-off deadline.',
            );
        }
        if ($credentials['account_state'] !== 'active') {
            $this->recordLoginAttempt($canonical, $ipAddress, false, 'account_closed');
            throw new ApiException(410, 'account_closed', 'This account has been closed.');
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

        $user = $this->users->findAuthenticatedById($credentials['id']);
        if ($user === null) {
            throw new RuntimeException('Authenticated user could not be loaded.');
        }
        // During maintenance lockdown only Super-Administrators get past the password step.
        (new LockdownService($this->pdo))->assertSignInAllowed($user);

        return $user;
    }

    public function completeLogin(AuthenticatedUser $user, string $ipAddress): void
    {
        // Every sign-in path ends here, including MFA and restoration; lockdown may have begun mid-flow.
        (new LockdownService($this->pdo))->assertSignInAllowed($user);
        $current = $this->users->findAuthenticatedById($user->id);
        if (
            $current === null
            || $current->sessionVersion !== $user->sessionVersion
            || $this->users->activeBan($user->id) !== null
        ) {
            throw new ApiException(401, 'authentication_required', 'Authentication is required.');
        }

        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare last-login update.');
        }
        $statement->execute(['id' => $user->id]);
        $this->recordLoginAttempt(Username::canonical($user->username), $ipAddress, true, 'success');
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

        // An account created through Google or Twitch sets its first password
        // here; the endpoint requires privileged step-up for that instead.
        $hadPassword = $this->users->hasPassword($actor->id);
        if ($hadPassword && !password_verify($currentPassword, $credentials['password_hash'])) {
            throw new ApiException(403, 'invalid_current_password', 'The current password is incorrect.');
        }

        PasswordPolicy::validate($newPassword, $credentials['username']);

        $this->pdo->beginTransaction();
        try {
            $this->users->updatePassword($actor->id, password_hash($newPassword, PASSWORD_DEFAULT));
            $this->audit->log(
                actorUserId: $actor->id,
                action: $hadPassword ? 'auth.password_changed' : 'auth.password_set',
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

    private function failedAttemptCountByUsername(string $canonical, int $windowSeconds): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM login_attempts
WHERE successful = FALSE
  AND created_at >= NOW() - make_interval(secs => CAST(:seconds AS double precision))
  AND username_canonical = :username
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare login throttle lookup.');
        }

        $statement->execute([
            'seconds' => $windowSeconds,
            'username' => $canonical,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function failedAttemptCountByIp(string $ipAddress, int $windowSeconds): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM login_attempts
WHERE successful = FALSE
  AND created_at >= NOW() - make_interval(secs => CAST(:seconds AS double precision))
  AND ip_address = :ip
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare login throttle lookup.');
        }

        $statement->execute([
            'seconds' => $windowSeconds,
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
