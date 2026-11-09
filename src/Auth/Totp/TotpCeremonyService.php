<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\LockdownService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\MfaPolicyGuard;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\NativeAuth\NativeAuthContinuation;
use ChitChat\Auth\NativeAuth\NativeAuthGuard;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use SensitiveParameter;

/** PHP authentication/elevation follows the atomic database effect and replay fence. */
final readonly class TotpCeremonyService
{
    public function __construct(private PDO $pdo, private Config $config) {}

    /** @return array<string,mixed> */
    public function login(#[SensitiveParameter] string $code, string $ip): array
    {
        if (NativeAuthContinuation::pending()) {
            return (new NativeAuthContinuation($this->pdo, $this->config))->finishMfa($code, 'totp', $ip);
        }
        SessionManager::assertPendingMfaTransport($ip);
        $user = SessionManager::pendingMfaUser(new UserRepository($this->pdo));
        $flow = SessionManager::pendingMfaFlow();
        $result = (new TotpService($this->pdo, $this->config))->complete($user, $code, 'mfa_login', $ip,
            function (callable $verify) use ($user, $flow, $ip): array {
                // Share the existing policy/closure/sorted-account lock protocol. The
                // replaced login may belong to another account; take both before factors.
                (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy(
                    $user->id, $flow === 'restore', SessionManager::replacedLoginBinding()['user_id'] ?? null);
                SessionManager::assertPendingMfaTransport($ip);
                $fresh = SessionManager::pendingMfaUser(new UserRepository($this->pdo));
                if ($fresh->id !== $user->id || SessionManager::pendingMfaFlow() !== $flow
                    || !(new MfaService($this->pdo, $this->config))->requiresMfaForLogin($fresh)) {
                    throw new ApiException(401, 'mfa_login_expired', 'Start sign-in again.');
                }
                (new LockdownService($this->pdo))->assertSignInAllowed($fresh);
                $verify();
                // NO KEY UPDATE throughout: restoration changes no referenced key.
                if ($flow === 'restore') {
                    $fresh = (new AccountClosureService($this->pdo, $this->config))
                        ->completeRestoreInTransaction($fresh->id, $ip, nativeHandoff: true);
                }
                (new AuthService($this->pdo, $this->config))->completeLogin($fresh, $ip);
                return ['user' => $fresh, 'login' => (new LoginSessionRepository($this->pdo))
                    ->issue($fresh, $_SERVER['HTTP_USER_AGENT'] ?? null, SessionManager::replacedLoginBinding())];
            });
        SessionManager::login($result['user'], $result['login']);
        SessionManager::establishPrivilegedStepUp($result['user'], 'totp');
        return ['csrf_token' => SessionManager::csrfToken(), 'user' => $result['user']->toSessionArray(),
            'mfa_method' => 'totp', 'restored' => $flow === 'restore'];
    }

    /** @return array<string,mixed> */
    public function stepUp(AuthenticatedUser $actor, #[SensitiveParameter] string $code, string $ip): array
    {
        $fresh = (new TotpService($this->pdo, $this->config))->complete($actor, $code, 'mfa_step_up', $ip,
            function (callable $verify) use ($actor): AuthenticatedUser {
                $fresh = (new MfaPolicyGuard($this->pdo, $this->config))->lock($actor, false);
                $verify();
                return $fresh;
            });
        SessionManager::establishPrivilegedStepUp($fresh, 'totp');
        return ['security' => ['privileged_step_up' => SessionManager::privilegedStepUpStatus($fresh, $this->config)]];
    }
}