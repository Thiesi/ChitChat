<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;

/** A consumed exchange may authorize one bounded, session-bound MFA or sign-up continuation. */
final readonly class NativeAuthContinuation
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
    }

    public static function pending(): bool
    {
        return NativeAuthSession::continuation() !== null
            || (is_array($_SESSION['pending_mfa'] ?? null) && isset($_SESSION['pending_mfa']['native_transaction']))
            || (is_array($_SESSION['oidc_sign_up'] ?? null) && isset($_SESSION['oidc_sign_up']['native_transaction']));
    }

    /** Bootstrap must not advertise an expired/revoked native continuation as usable. */
    public function reconcileSession(): void
    {
        if (!self::pending()) return;
        try {
            $row = $this->snapshot();
            if ($row['outcome'] === 'pending_mfa') {
                SessionManager::pendingMfaUser(new UserRepository($this->pdo));
            } elseif ($row['outcome'] !== 'pending_sign_up') throw NativeAuthGuard::failure('already_used');
        } catch (ApiException) {
            $this->clearLocal();
        }
    }

    /** @return array<string,mixed> */
    public function mfaOptions(string $ip): array
    {
        SessionManager::assertPendingMfaTransport($ip);
        $this->snapshot('pending_mfa');
        $user = SessionManager::pendingMfaUser(new UserRepository($this->pdo));
        return ['public_key' => (new MfaService($this->pdo, $this->config))->beginAssertion($user, 'mfa_login', $ip)];
    }

    /** @param array<string,mixed>|string $proof
     * @return array<string,mixed>
     */
    public function finishMfa(#[\SensitiveParameter] array|string $proof, string $method, string $ip): array
    {
        SessionManager::assertPendingMfaTransport($ip);
        $snapshot = $this->snapshot('pending_mfa');
        $pendingUser = SessionManager::pendingMfaUser(new UserRepository($this->pdo));
        $complete = function (callable $verify) use ($snapshot, $ip, $method): NativeAuthOutcome {
            $this->guard->lockAccountPolicy((int) $snapshot['user_id'], $snapshot['purpose'] === 'restore',
                SessionManager::replacedLoginBinding()['user_id'] ?? null);
            $row = $this->snapshot('pending_mfa', true);
            $user = $this->guard->account($row) ?? throw NativeAuthGuard::failure('account_changed');
            (new LockdownService($this->pdo))->assertSignInAllowed($user);
            SessionManager::pendingMfaUser(new UserRepository($this->pdo));
            $verify(); // Factor consumption and primary effects share the outer transaction.
            $pending = $_SESSION['pending_mfa'] ?? null;
            if (!is_array($pending) || !is_int($pending['expires_at'] ?? null) || $pending['expires_at'] <= time()) throw NativeAuthGuard::failure('expired');
            if ($row['purpose'] === 'restore') {
                $deadline = $this->rows->statement('SELECT closure_finalizes_at FROM users WHERE id = :id', ['id' => $user->id])->fetchColumn();
                if (!is_string($deadline) || new DateTimeImmutable($deadline) <= $this->guard->now()) throw new ApiException(410, 'account_restoration_expired', 'The account-restoration deadline has passed.');
                $user = (new AccountClosureService($this->pdo, $this->config))->completeRestoreInTransaction($user->id, $ip, nativeHandoff: true);
            }
            (new AuthService($this->pdo, $this->config))->completeLogin($user, $ip);
            $this->usable($row);
            $this->rows->consume((string) $row['id'], 'completed');
            $this->audit($row, $user->id, $ip);
            return (new NativeAuthOutcome((string) $row['id'], (string) $row['purpose'], (string) $row['provider'], 'completed', $user, null, 0, $method))
                ->withLogin((new \ChitChat\Auth\LoginSessionRepository($this->pdo))->issue($user, $_SERVER['HTTP_USER_AGENT'] ?? null, SessionManager::replacedLoginBinding()));
        };
        $mfa = new MfaService($this->pdo, $this->config);
        if ($method === 'passkey' && is_array($proof)) {
            $outcome = $mfa->completeNativeWithAssertion($pendingUser, $proof, $ip, $complete);
        } elseif ($method === 'recovery_code' && is_string($proof)) {
            $outcome = $mfa->completeNativeWithRecoveryCode($pendingUser, $proof, $ip, $complete);
        } else throw new ApiException(400, 'validation_error', 'Invalid native second-factor proof.');
        $result = $outcome->install($this->config, $ip, new OidcService($this->pdo, $this->config));
        if ($method === 'recovery_code') $result['recovery_codes_remaining'] = (new MfaRepository($this->pdo))->recoveryCodesRemaining($pendingUser->id);
        return $result;
    }

    /** Registration challenge is checked by the existing endpoint before this rules/mutation boundary.
     * @return array<string,mixed>
     */
    public function finishSignUp(string $username, ?string $birthDate, mixed $acceptedRulesVersion, string $ip): array
    {
        $this->snapshot('pending_sign_up');
        if ($this->pdo->inTransaction()) throw new \RuntimeException('Native sign-up must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $this->guard->lockAccountPolicy(null);
            $row = $this->snapshot('pending_sign_up', true);
            $context = NativeAuthSession::continuation() ?? throw NativeAuthGuard::failure('native_session_changed');
            if ($this->guard->identityUser((string) $row['provider'], $context['subject'], true) !== null) throw NativeAuthGuard::failure('identity_changed');
            $user = (new AuthService($this->pdo, $this->config))->registerWithProviderInTransaction(
                $username, (string) $row['provider'], $context['subject'], $ip, $birthDate, $acceptedRulesVersion);
            $this->usable($row);
            $this->rows->statement('UPDATE native_auth_transactions SET user_id = :user_id, user_version = :version WHERE id = :id',
                ['id' => $row['id'], 'user_id' => $user->id, 'version' => $user->sessionVersion]);
            $this->rows->consume((string) $row['id'], 'completed');
            $this->audit($row, $user->id, $ip);
            $outcome = (new NativeAuthOutcome((string) $row['id'], 'sign_up', (string) $row['provider'], 'completed', $user, null, 0))
                ->withLogin((new \ChitChat\Auth\LoginSessionRepository($this->pdo))->issue($user, $_SERVER['HTTP_USER_AGENT'] ?? null, SessionManager::replacedLoginBinding()));
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        return $outcome->install($this->config, $ip, new OidcService($this->pdo, $this->config));
    }

    public function cancelSignUp(): void
    {
        $context = NativeAuthSession::continuation();
        if ($context === null) { $this->clearLocal(); return; }
        if ($this->pdo->inTransaction()) throw new \RuntimeException('Native cancellation must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $row = $this->rows->require($context['transaction_id'], true);
            $this->guard->nativeOwner($row);
            if ($row['phase'] === 'consumed' && (int) $row['active'] === 1 && $row['outcome'] === 'pending_sign_up') {
                $this->rows->terminal((string) $row['id'], 'cancelled');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        $this->clearLocal();
    }

    /** @return array<string,mixed> */
    private function snapshot(?string $expected = null, bool $lock = false): array
    {
        $context = NativeAuthSession::continuation();
        if ($context === null) throw NativeAuthGuard::failure('native_session_changed');
        $row = $this->rows->require($context['transaction_id'], $lock);
        $this->guard->nativeOwner($row);
        $this->usable($row);
        if ($row['phase'] !== 'consumed' || !in_array($row['outcome'], ['pending_mfa', 'pending_sign_up'], true)
            || ($expected !== null && $row['outcome'] !== $expected) || !is_string($row['subject_hash'])
            || !hash_equals($row['subject_hash'], hash('sha256', $context['subject']))) throw NativeAuthGuard::failure('native_session_changed');
        if (SessionManager::currentUser(new UserRepository($this->pdo)) !== null) throw NativeAuthGuard::failure('native_session_changed');
        $pending = $row['outcome'] === 'pending_mfa' ? ($_SESSION['pending_mfa'] ?? null) : ($_SESSION['oidc_sign_up'] ?? null);
        if (!is_array($pending) || ($pending['native_transaction'] ?? null) !== $row['id']) throw NativeAuthGuard::failure('native_session_changed');
        if ($row['outcome'] === 'pending_sign_up') {
            if (($pending['provider'] ?? null) !== $row['provider'] || ($pending['subject'] ?? null) !== $context['subject']
                || !is_int($pending['created_at'] ?? null) || $pending['created_at'] <= time() - NativeAuthConfiguration::TRANSACTION_TTL) throw NativeAuthGuard::failure('native_session_changed');
        } else {
            if (($pending['user_id'] ?? null) !== (int) $row['user_id'] || ($pending['session_version'] ?? null) !== (int) $row['user_version']
                || ($pending['flow'] ?? null) !== ($row['purpose'] === 'restore' ? 'restore' : 'login')) throw NativeAuthGuard::failure('native_session_changed');
            $this->guard->account($row);
            if ($this->guard->identityUser((string) $row['provider'], $context['subject'], $lock) !== (int) $row['user_id']) throw NativeAuthGuard::failure('identity_changed');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function usable(array $row): void
    {
        $error = $this->guard->errorCode($row, true);
        if ($error !== null) throw NativeAuthGuard::failure($error);
    }

    /** @param array<string,mixed> $row */
    private function audit(array $row, int $userId, string $ip): void
    {
        (new AuditLogger($this->pdo))->log($userId, 'auth.native_continuation_completed', 'native_auth_transaction', (string) $row['id'],
            ['purpose' => (string) $row['purpose'], 'client_id' => (string) $row['client_id']], $ip);
    }

    private function clearLocal(): void
    {
        unset($_SESSION['pending_mfa'], $_SESSION['oidc_sign_up'], $_SESSION['webauthn_ceremony']);
        NativeAuthSession::clearContinuation();
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}