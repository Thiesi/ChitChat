<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Account\AccountClosureService;
use ChitChat\Account\AvatarService;
use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class NativeAuthEffects
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;
    private AuditLogger $audit;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
        $this->audit = new AuditLogger($pdo);
    }

    /** The caller holds rules, policy, account and native-row locks; no provider HTTP is allowed here.
     * @param array<string,mixed> $row
     * @param array{subject:string,auth_time:?int,started_at:int} $proof
     */
    public function apply(array $row, array $proof, string $ip): NativeAuthOutcome
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Native effects require a transaction.');
        $purpose = (string) $row['purpose'];
        $provider = (string) $row['provider'];
        $subject = $proof['subject'];
        $identityUser = $this->guard->identityUser($provider, $subject, true);
        $user = $this->guard->account($row);
        $outcome = 'completed';
        if ($purpose === 'login' && $user === null) {
            if ($identityUser !== null) throw NativeAuthGuard::failure('identity_changed');
            (new LockdownService($this->pdo))->assertOpen();
            if ((int) $this->rows->statement('SELECT registration_enabled::int FROM system_settings WHERE id = 1')->fetchColumn() !== 1) {
                throw new ApiException(403, 'registration_disabled', 'Registration is currently disabled.');
            }
            $outcome = 'pending_sign_up';
        } else {
            if ($user === null) throw NativeAuthGuard::failure('account_changed');
            if ($purpose === 'link') {
                SessionManager::requirePrivilegedStepUp($user, $this->config);
                (new LockdownService($this->pdo))->assertSignInAllowed($user);
                if ($identityUser !== null && $identityUser !== $user->id) throw NativeAuthGuard::failure('identity_changed');
                if ($identityUser === null) {
                    $this->rows->statement('INSERT INTO user_identities (user_id,provider,subject) VALUES (:user_id,:provider,:subject)',
                        ['user_id' => $user->id, 'provider' => $provider, 'subject' => $subject]);
                    $this->audit->log($user->id, 'account.identity_linked', 'user', (string) $user->id, ['provider' => $provider], $ip);
                }
            } else {
                if ($identityUser !== $user->id) throw NativeAuthGuard::failure('identity_changed');
                if ($purpose === 'restore') (new LockdownService($this->pdo))->assertOpen();
                else (new LockdownService($this->pdo))->assertSignInAllowed($user);
                if ($purpose === 'step_up') {
                    if ((new MfaService($this->pdo, $this->config))->requiresMfaForLogin($user)) {
                        throw new ApiException(403, 'passkey_step_up_required', 'Use your passkey or recovery code in the app.');
                    }
                    if (($provider === 'google' && $proof['auth_time'] === null)
                        || ($proof['auth_time'] !== null && ($proof['auth_time'] < $proof['started_at'] - 60
                            || $proof['auth_time'] > $this->guard->now()->getTimestamp() + 60))) {
                        throw new ApiException(403, 'provider_reauthentication_required', 'Confirm with the provider again.');
                    }
                    $this->audit->log($user->id, 'auth.privileged_step_up_succeeded', 'user', (string) $user->id,
                        ['method' => $provider, 'max_age_seconds' => $this->config->privilegedStepUpMaxAgeSeconds], $ip);
                } elseif ($purpose === 'picture') {
                    if (!is_string($row['picture'])) throw new ApiException(422, 'invalid_avatar', 'No verified picture is ready.');
                    (new AvatarService($this->pdo, $this->config))->stashImport($user->id, $row['picture']);
                    $this->audit->log($user->id, 'account.avatar_imported', 'user', (string) $user->id, ['provider' => $provider], $ip);
                } elseif (in_array($purpose, ['login', 'restore'], true)) {
                    if ($purpose === 'restore') {
                        $deadline = $this->rows->statement('SELECT closure_finalizes_at FROM users WHERE id = :id', ['id' => $user->id])->fetchColumn();
                        if (!is_string($deadline) || new DateTimeImmutable($deadline) <= $this->guard->now()) {
                            throw new ApiException(410, 'account_restoration_expired', 'The account-restoration deadline has passed.');
                        }
                    }
                    $requiresMfa = (new MfaService($this->pdo, $this->config))->requiresMfaForLogin($user);
                    if ($requiresMfa) {
                        $outcome = 'pending_mfa';
                    } else {
                        if ($purpose === 'restore') $user = (new AccountClosureService($this->pdo, $this->config))->completeRestoreInTransaction($user->id, $ip);
                        (new AuthService($this->pdo, $this->config))->completeLogin($user, $ip);
                    }
                    $this->audit->log($user->id, 'auth.oidc_sign_in', 'user', (string) $user->id, ['provider' => $provider], $ip);
                }
                $this->rows->statement('UPDATE user_identities SET last_used_at = clock_timestamp() WHERE provider = :provider AND subject = :subject',
                    ['provider' => $provider, 'subject' => $subject]);
            }
        }
        $remaining = max(1, (new DateTimeImmutable((string) $row['expires_at']))->getTimestamp() - $this->guard->now()->getTimestamp());
        return new NativeAuthOutcome((string) $row['id'], $purpose, $provider, $outcome, $user,
            in_array($outcome, ['pending_mfa', 'pending_sign_up'], true) ? $subject : null, $remaining);
    }
}
