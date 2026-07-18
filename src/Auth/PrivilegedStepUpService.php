<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;

final class PrivilegedStepUpService
{
    private readonly UserRepository $users;
    private readonly MfaRepository $mfa;
    private readonly AuditLogger $audit;
    private readonly RateLimiter $rateLimiter;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->users = new UserRepository($pdo);
        $this->mfa = new MfaRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->rateLimiter = new RateLimiter($pdo, $config->rateLimits);
    }

    /** @return array{active:bool,method:?string,verified_at:?string,expires_at:?string,max_age_seconds:int} */
    public function verify(AuthenticatedUser $actor, string $password, string $ipAddress): array
    {
        if ($this->mfa->isEnabled($actor->id)) {
            throw new ApiException(
                403,
                'passkey_step_up_required',
                'This account requires a passkey or recovery code for sensitive actions.',
            );
        }
        $this->rateLimiter->consume('privileged_step_up', $actor->id . '|' . $ipAddress);
        $credentials = $this->users->findCredentialsById($actor->id);
        if ($credentials === null || $credentials['session_version'] !== $actor->sessionVersion) {
            throw new ApiException(401, 'authentication_required', 'Authentication is required.');
        }
        if ($password === '' || !password_verify($password, $credentials['password_hash'])) {
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'auth.privileged_step_up_failed',
                subjectType: 'user',
                subjectId: (string) $actor->id,
                metadata: ['method' => 'password'],
                ipAddress: $ipAddress,
            );
            throw new ApiException(403, 'step_up_invalid_credentials', 'The current password is incorrect.');
        }
        $this->audit->log(
            actorUserId: $actor->id,
            action: 'auth.privileged_step_up_succeeded',
            subjectType: 'user',
            subjectId: (string) $actor->id,
            metadata: ['method' => 'password', 'max_age_seconds' => $this->config->privilegedStepUpMaxAgeSeconds],
            ipAddress: $ipAddress,
        );
        SessionManager::establishPrivilegedStepUp($actor, 'password');

        return SessionManager::privilegedStepUpStatus($actor, $this->config);
    }
}
