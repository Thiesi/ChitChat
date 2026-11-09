<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/** Shared management lock order: installation policy, account, login, then factors. */
final readonly class MfaPolicyGuard
{
    public function __construct(private PDO $pdo, private Config $config) {}

    public function lock(AuthenticatedUser $actor, bool $stepUp): AuthenticatedUser
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('MFA account management requires a transaction.');
        // Match policy and login revocation: installation -> account -> login -> factor.
        $policy = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR SHARE');
        if ($policy === false || $policy->fetchColumn() === false) throw new RuntimeException('Account policy is unavailable.');
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
        if ($statement === false) throw new RuntimeException('Unable to lock authenticator account.');
        $statement->execute(['id' => $actor->id]);
        $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($actor->id);
        $binding = SessionManager::loginBinding();
        if ($fresh === null || $fresh->guest || $fresh->sessionVersion !== $actor->sessionVersion || $binding === null
            || $binding['user_id'] !== $fresh->id || $binding['version'] !== $fresh->sessionVersion) {
            throw new ApiException(401, 'authentication_required', 'Sign in again before managing an authenticator.');
        }
        $login = $this->pdo->prepare('SELECT id FROM login_sessions WHERE id = :id AND user_id = :user FOR SHARE');
        if ($login === false) throw new RuntimeException('Unable to lock authenticator login.');
        $login->execute(['id' => $binding['id'], 'user' => $fresh->id]);
        $reason = (new LoginSessionRepository($this->pdo))->invalidReason($fresh->id, $fresh->sessionVersion, $binding['id'], $binding['hash']);
        if ($reason !== null) throw new ApiException(401, $reason, 'Sign in again before managing an authenticator.');
        if ((new UserRepository($this->pdo))->activeBan($fresh->id) !== null) {
            throw new ApiException(403, 'account_banned', 'This account is banned.');
        }
        if ($stepUp) SessionManager::requirePrivilegedStepUp($fresh, $this->config);
        return $fresh;
    }

}
