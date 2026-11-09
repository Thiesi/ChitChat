<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Admin\LockdownService;
use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** A password recovery key is primary proof; enabled MFA is always verified separately. */
final class PasswordResetService
{
    private readonly UserRepository $users;
    private readonly RecoveryKeyService $keys;
    private readonly AuditLogger $audit;
    private readonly RateLimiter $limits;
    private readonly MfaService $mfa;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->users = new UserRepository($pdo);
        $this->keys = new RecoveryKeyService($pdo, $config);
        $this->audit = new AuditLogger($pdo);
        $this->limits = new RateLimiter($pdo, $config->rateLimits);
        $this->mfa = new MfaService($pdo, $config);
    }

    /** @return array{challenge:array{nonce:string,bits:int},csrf_token:string} */
    public function challenge(string $ipAddress): array
    {
        $this->requireAnonymous();
        $this->limits->consume('password_reset_challenge', 'ip:' . $ipAddress);
        $bits = (new RegistrationProtectionService($this->pdo, $this->config))->effective()['proof_of_work_bits'];
        return ['challenge' => (new PasswordResetChallenge($bits))->issue($_SESSION, time()),
            'csrf_token' => SessionManager::csrfToken()];
    }

    /** @return array<string,mixed> */
    public function begin(
        string $usernameInput,
        #[\SensitiveParameter] string $recoveryKey,
        #[\SensitiveParameter] string $newPassword,
        mixed $nonce,
        mixed $solution,
        string $ipAddress,
    ): array {
        $this->requireAnonymous();
        // Even rejected/throttled submissions consume their supplied one-use challenge.
        $challengeValid = (new PasswordResetChallenge(0))->consume($_SESSION, $nonce, $solution, time());
        $canonical = null;
        try {
            $canonical = Username::canonical($usernameInput);
        } catch (ApiException) {
            // Invalid names get the same recovery failure, after the independent IP budget.
        }
        $identifier = $canonical ?? 'invalid:' . hash('sha256', $usernameInput);
        $this->limits->consume('password_reset_ip', 'ip:' . $ipAddress);
        $this->limits->consume('password_reset', 'username:' . $identifier);
        $this->audit->log(null, 'auth.password_reset_requested', 'password_reset', 'public', [], $ipAddress);
        try {
            if (!$challengeValid || $canonical === null) {
                throw self::failed();
            }
            try {
                PasswordPolicy::validate($newPassword, Username::display($usernameInput));
            } catch (ApiException $exception) {
                throw new ApiException(400, 'validation_error', $exception->getMessage());
            }
            $hash = PasswordRecoveryKey::hash($recoveryKey);
            // Every well-formed negative attempt performs one lookup and a hash comparison,
            // regardless of whether the username exists or has a recovery key.
            $lookup = $this->prepare(<<<'SQL'
SELECT u.id, recovery.key_hash
FROM users u LEFT JOIN password_recovery_keys recovery ON recovery.user_id = u.id
WHERE u.username_canonical = :name
SQL);
            $lookup->execute(['name' => $canonical]);
            $candidate = $lookup->fetch();
            $storedHash = is_array($candidate) && is_string($candidate['key_hash'])
                ? $candidate['key_hash'] : str_repeat('1', 64);
            $matches = hash_equals($storedHash, $hash ?? str_repeat('0', 64));
            if (!$matches || $hash === null || !is_array($candidate) || $candidate['key_hash'] === null) {
                throw self::failed();
            }
            // Bcrypt work is outside the account/policy lock, but proof is checked again under lock.
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->pdo->beginTransaction();
            $this->keys->lockPolicy();
            [$user, $generation, $currentHash] = $this->primary((int) $candidate['id']);
            if (!hash_equals($currentHash, $hash)) {
                throw self::failed();
            }
            (new LockdownService($this->pdo))->assertSignInAllowed($user);
            $required = $this->mfa->requiresMfaForLogin($user);
            if ($required) {
                // Only reads were made in this transaction; no recovery material is consumed yet.
                $this->pdo->commit();
                SessionManager::beginPasswordReset($user, $passwordHash, $generation, $ipAddress, $this->config->mfaPendingLoginTtlSeconds);
                $pending = SessionManager::pendingPasswordReset();
                return ['status' => 'pending_mfa', 'mfa_required' => true,
                    'csrf_token' => SessionManager::csrfToken(), 'methods' => $this->mfa->pendingMethods($user),
                    'expires_at' => gmdate(DATE_ATOM, $pending['expires_at'])];
            }
            $result = $this->commitChanges($user, $passwordHash, $ipAddress, false);
            $this->pdo->commit();
            return $this->signedIn($result);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->failure($ipAddress, 'initiation');
            throw $this->publicFailure($exception);
        }
    }

    /** @return array<string,mixed> */
    public function options(string $ipAddress): array
    {
        [$user] = $this->pending($ipAddress);
        return $this->mfa->beginAssertion($user, 'mfa_password_reset', $ipAddress);
    }

    /**
     * @param array<string,mixed> $credential
     * @return array<string,mixed>
     */
    public function finishAssertion(array $credential, string $ipAddress): array
    {
        [$user, $pending] = $this->pending($ipAddress);
        try {
            $result = $this->mfa->completeResetWithAssertion($user, $credential, $ipAddress,
                fn (callable $verify): array => $this->completePending($pending, $ipAddress, $verify));
            return $this->signedIn($result);
        } catch (Throwable $exception) {
            $this->failure($ipAddress, 'mfa');
            $public = $this->publicFailure($exception);
            if ($public instanceof ApiException && $public->errorCode === 'reset_failed') {
                SessionManager::clearPendingPasswordReset();
            }
            throw $public;
        }
    }

    /** @return array<string,mixed> */
    public function finishRecovery(#[\SensitiveParameter] string $code, string $ipAddress): array
    {
        [$user, $pending] = $this->pending($ipAddress);
        try {
            $result = $this->mfa->completeResetWithRecoveryCode($user, $code, $ipAddress,
                fn (callable $verify): array => $this->completePending($pending, $ipAddress, $verify));
            return $this->signedIn($result);
        } catch (Throwable $exception) {
            $this->failure($ipAddress, 'mfa');
            $public = $this->publicFailure($exception);
            if ($public instanceof ApiException && $public->errorCode === 'reset_failed') {
                SessionManager::clearPendingPasswordReset();
            }
            throw $public;
        }
    }

    /** @return array<string,mixed> */
    public function finishTotp(#[\SensitiveParameter] string $code, string $ipAddress): array
    {
        [$user, $pending] = $this->pending($ipAddress);
        try {
            $result = (new \ChitChat\Auth\Totp\TotpService($this->pdo, $this->config))
                ->complete($user, $code, 'mfa_password_reset', $ipAddress,
                    fn (callable $verify): array => $this->completePending($pending, $ipAddress, $verify));
            return $this->signedIn($result);
        } catch (Throwable $exception) {
            $this->failure($ipAddress, 'mfa');
            $public = $this->publicFailure($exception);
            if ($public instanceof ApiException && $public->errorCode === 'reset_failed') {
                SessionManager::clearPendingPasswordReset();
            }
            throw $public;
        }
    }

    /** @return array{status:string,csrf_token:string} */
    public function cancel(string $ipAddress): array
    {
        try {
            // Persisted pending state can survive a committed reset if the response/session
            // cleanup was interrupted. Check the live version/generation before promising no commit.
            $this->pending($ipAddress);
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'reset_failed') {
                throw $exception;
            }
            throw new ApiException(409, 'reset_not_pending', 'No pending reset was cancelled. Check sign-in before trying again.');
        }
        SessionManager::clearPendingPasswordReset();
        $this->audit->log(null, 'auth.password_reset_cancelled', 'password_reset', 'pending', [], $ipAddress);
        return ['status' => 'cancelled', 'csrf_token' => SessionManager::csrfToken()];
    }

    /**
     * @param array{user_id:int,session_version:int,password_hash:string,key_generation:string,ip_address:string,expires_at:int} $pending
     * @param callable():void $verify
     *
     * @return array{user:AuthenticatedUser,recovery_key:string,login:IssuedLogin}
     */
    private function completePending(#[\SensitiveParameter] array $pending, string $ipAddress, callable $verify): array
    {
        $this->keys->lockPolicy();
        [$user, $generation] = $this->primary($pending['user_id']);
        if ($pending['expires_at'] <= time() || !hash_equals($pending['ip_address'], $ipAddress)
            || $user->sessionVersion !== $pending['session_version']
            || !hash_equals($generation, $pending['key_generation'])) {
            throw self::failed();
        }
        (new LockdownService($this->pdo))->assertSignInAllowed($user);
        if (!$this->mfa->requiresMfaForLogin($user)) {
            throw self::failed();
        }
        $verify();
        return $this->commitChanges($user, $pending['password_hash'], $ipAddress, true);
    }

    /** @return array{AuthenticatedUser,string,string} */
    private function primary(int $id): array
    {
        $this->keys->lockAccount($id);
        $user = $this->users->findAuthenticatedById($id);
        $row = $this->keyRow($id);
        if ($user === null || $row === null || $this->users->activeBan($id) !== null) {
            throw self::failed();
        }
        return [$user, (string) $row['generation'], (string) $row['key_hash']];
    }

    /** @return array<string,mixed>|null */
    private function keyRow(int $userId): ?array
    {
        $statement = $this->prepare('SELECT key_hash, generation FROM password_recovery_keys WHERE user_id = :id FOR UPDATE');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     *
     * @return array{AuthenticatedUser,array{user_id:int,session_version:int,password_hash:string,key_generation:string,ip_address:string,expires_at:int}}
     */
    private function pending(string $ipAddress): array
    {
        $state = SessionManager::pendingPasswordReset();
        $user = $this->users->findAuthenticatedById($state['user_id']);
        $statement = $this->prepare('SELECT generation FROM password_recovery_keys WHERE user_id = :id');
        $statement->execute(['id' => $state['user_id']]);
        $generation = $statement->fetchColumn();
        if ($user === null || $user->sessionVersion !== $state['session_version']
            || !hash_equals($state['ip_address'], $ipAddress) || !is_string($generation)
            || !hash_equals($generation, $state['key_generation']) || $this->users->activeBan($state['user_id']) !== null) {
            SessionManager::clearPendingPasswordReset();
            throw self::failed();
        }
        return [$user, $state];
    }

    /** @return array{user:AuthenticatedUser,recovery_key:string,login:IssuedLogin} */
    private function commitChanges(AuthenticatedUser $user, #[\SensitiveParameter] string $passwordHash, string $ipAddress, bool $mfa): array
    {
        $this->users->updatePassword($user->id, $passwordHash);
        $key = $this->keys->issueInTransaction($user->id);
        $fresh = $this->users->findAuthenticatedById($user->id);
        if ($fresh === null) {
            throw new RuntimeException('Reset account could not be reloaded.');
        }
        (new AuthService($this->pdo, $this->config))->completeLogin($fresh, $ipAddress);
        $this->audit->log($fresh->id, 'auth.password_reset_succeeded', 'user', (string) $fresh->id, ['mfa' => $mfa], $ipAddress);
        return ['user' => $fresh, 'recovery_key' => $key['recovery_key'],
            'login' => (new LoginSessionRepository($this->pdo))->issue($fresh, $_SERVER['HTTP_USER_AGENT'] ?? null)];
    }

    /**
     * @param array{user:AuthenticatedUser,recovery_key:string,login:IssuedLogin} $result
     * @return array<string,mixed>
     */
    private function signedIn(#[\SensitiveParameter] array $result): array
    {
        // The DB commit already happened. Clear pending state before fallible session-ID
        // rotation, so a failed response cannot later acknowledge this reset as cancelled.
        unset($_SESSION['pending_password_reset'], $_SESSION['password_reset_challenge']);
        SessionManager::login($result['user'], $result['login']); // Clears elevation; never grants step-up.
        return ['status' => 'complete', 'user' => $result['user']->toSessionArray(),
            'csrf_token' => SessionManager::csrfToken(), 'recovery_key' => $result['recovery_key']];
    }

    private function requireAnonymous(): void
    {
        if (SessionManager::currentUserOrNull($this->users) !== null) {
            throw new ApiException(409, 'already_signed_in', 'Sign out before using password recovery.');
        }
    }

    public static function failed(): ApiException
    {
        return new ApiException(400, 'reset_failed', 'Password reset could not be verified. Check your details and try again.');
    }

    private function publicFailure(Throwable $exception): Throwable
    {
        if ($exception instanceof ApiException && in_array($exception->errorCode,
            ['authentication_required', 'password_account_required'], true)) {
            return self::failed();
        }
        return $exception;
    }

    private function failure(string $ipAddress, string $phase): void
    {
        $this->audit->log(null, 'auth.password_reset_failed', 'password_reset', 'public', ['phase' => $phase], $ipAddress);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare password-reset operation.');
        }
        return $statement;
    }
}
