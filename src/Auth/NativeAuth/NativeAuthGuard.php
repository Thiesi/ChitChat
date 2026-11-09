<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class NativeAuthGuard
{
    public function __construct(private PDO $pdo, private Config $config) {}

    public static function failure(string $code): ApiException
    {
        [$status, $message] = match ($code) {
            'expired' => [410, 'This request expired. Start again from the app.'],
            'code_expired' => [410, 'The return code expired. Start again from the app.'],
            'superseded' => [409, 'A newer request replaced this one.'],
            'cancelled' => [409, 'This request was cancelled.'],
            'already_used' => [409, 'This return code was already used. Check the app.'],
            'state_mismatch' => [403, 'The returned state does not match this request.'],
            'verifier_mismatch' => [403, 'The PKCE verifier does not match this request.'],
            'native_session_changed' => [401, 'The app session changed. Start again from the app.'],
            'native_config_changed' => [409, 'Native sign-in configuration changed. Start again from the app.'],
            'identity_changed' => [409, 'The connected provider identity changed. Start again from the app.'],
            'account_changed' => [409, 'The account changed. Start again from the app.'],
            default => [409, 'This native sign-in request is unavailable.'],
        };
        return new ApiException($status, $code, $message);
    }

    /** @param array<string,mixed> $row */
    public function nativeOwner(array $row): void
    {
        $binding = NativeAuthSession::nativeHash();
        if ($binding === null || !hash_equals((string) $row['native_binding_hash'], $binding)) {
            throw self::failure('native_session_changed');
        }
    }

    /** @param array<string,mixed> $row */
    public function browserOwner(array $row, ?AuthenticatedUser $actor): void
    {
        $browser = NativeAuthSession::browser($actor);
        if ($browser === null || $browser['transaction_id'] !== $row['id']
            || !is_string($row['browser_binding_hash'])
            || !hash_equals($row['browser_binding_hash'], $browser['binding_hash'])) {
            throw new ApiException(403, 'browser_session_changed', 'Start again from the app.');
        }
    }

    /** @param array<string,mixed> $row */
    public function errorCode(array $row, bool $continuation = false): ?string
    {
        $phase = (string) $row['phase'];
        $now = $this->now();
        if (in_array($phase, ['cancelled', 'superseded', 'expired'], true)) {
            return is_string($row['error_code']) ? $row['error_code'] : $phase;
        }
        if ($phase === 'consumed' && (int) $row['active'] !== 1) return 'already_used';
        if (new DateTimeImmutable((string) $row['expires_at']) <= $now) return 'expired';
        if ($row['callback_expires_at'] !== null && new DateTimeImmutable((string) $row['callback_expires_at']) <= $now) return 'expired';
        if ($phase === 'staged' && $row['code_expires_at'] !== null
            && new DateTimeImmutable((string) $row['code_expires_at']) <= $now) return 'code_expired';
        if (!NativeAuthConfiguration::configured($this->config)
            || !hash_equals((string) $row['config_hash'], NativeAuthConfiguration::fingerprint($this->config))) {
            return 'native_config_changed';
        }
        if ($phase === 'consumed' && !$continuation) return 'already_used';
        return null;
    }

    public function now(): DateTimeImmutable
    {
        $statement = $this->pdo->query('SELECT clock_timestamp()');
        $value = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($value)) throw new RuntimeException('Native clock is unavailable.');
        return new DateTimeImmutable($value);
    }

    /** Must precede the native transaction row and every credential/identity write lock. */
    public function lockAccountPolicy(?int $userId, bool $restoration = false, ?int $replacedUserId = null): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Native account locks require a transaction.');
        // Match registration and policy writes: rules, installation policy, account, then native row.
        $rules = $this->pdo->query("SELECT document FROM policy_documents WHERE document = 'rules' FOR SHARE");
        if ($rules === false || $rules->fetchColumn() === false) throw new RuntimeException('Rules policy is unavailable.');
        // Deliberately serialize native account work installation-wide at this scale,
        // preserving a consistent policy/account/native-row lock order.
        $statement = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
        if ($statement === false || $statement->fetchColumn() === false) throw new RuntimeException('Account policy is unavailable.');
        if ($restoration && $userId !== null) {
            // Match finalization: closure first, then account, then the native transaction.
            $closure = $this->pdo->prepare('SELECT id FROM account_closures WHERE user_id = :id AND restored_at IS NULL AND finalized_at IS NULL FOR UPDATE');
            if ($closure === false) throw new RuntimeException('Unable to prepare native closure lock.');
            $closure->execute(['id' => $userId]);
            if ($closure->fetchColumn() === false) throw self::failure('account_changed');
        }
        // Native completion may retire a previous browser's login on another account.
        // Prelock both accounts in ID order, before native/factor/login rows; ordinary
        // web issuance uses this same order without taking the native policy mutex.
        $accounts = array_unique([...($userId === null ? [] : [$userId]),
            ...($replacedUserId === null ? [] : [$replacedUserId])]);
        sort($accounts, SORT_NUMERIC);
        foreach ($accounts as $id) {
            $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
            if ($statement === false) throw new RuntimeException('Unable to prepare native account lock.');
            $statement->execute(['id' => $id]);
            if ($statement->fetchColumn() === false && $id === $userId) throw self::failure('account_changed');
        }
    }

    /** @param array<string,mixed> $row */
    public function account(array $row): ?AuthenticatedUser
    {
        if ($row['user_id'] === null) return null;
        $users = new UserRepository($this->pdo);
        $user = $row['purpose'] === 'restore'
            ? $users->findClosurePendingById((int) $row['user_id'])
            : $users->findAuthenticatedById((int) $row['user_id']);
        if ($user === null || $user->guest || $user->sessionVersion !== (int) $row['user_version']
            || $users->activeBan($user->id) !== null) throw self::failure('account_changed');
        return $user;
    }

    /** @param array<string,mixed> $row */
    public function nativeIdentity(array $row, ?AuthenticatedUser $actor): void
    {
        $requiresActor = in_array($row['purpose'], ['link', 'step_up', 'picture'], true);
        if ($requiresActor) {
            $login = SessionManager::loginBinding();
            if ($login === null || ($row['login_session_id'] ?? null) !== $login['id']) throw self::failure('native_session_changed');
            if ((new \ChitChat\Auth\LoginSessionRepository($this->pdo))->invalidReason(
                $login['user_id'], $login['version'], $login['id'], $login['hash']) !== null) throw self::failure('native_session_changed');
        }
        if ($requiresActor) {
            if ($actor === null || $actor->guest || $actor->id !== (int) $row['user_id']
                || $actor->sessionVersion !== (int) $row['user_version']) throw self::failure('native_session_changed');
        } elseif ($actor !== null) {
            throw self::failure('native_session_changed');
        }
    }

    public function identityUser(string $provider, string $subject, bool $lock = false): ?int
    {
        $statement = $this->pdo->prepare('SELECT user_id FROM user_identities WHERE provider = :provider AND subject = :subject'
            . ($lock ? ' FOR SHARE' : ''));
        if ($statement === false) throw new RuntimeException('Unable to prepare native identity lookup.');
        $statement->execute(['provider' => $provider, 'subject' => $subject]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $row
     * @return array{subject:string,auth_time:?int,started_at:int}
     */
    public function proof(array $row): array
    {
        $proof = is_string($row['proof']) ? json_decode($row['proof'], true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($proof) || !is_string($proof['subject'] ?? null) || $proof['subject'] === ''
            || strlen($proof['subject']) > 255 || !is_int($proof['started_at'] ?? null)
            || (isset($proof['auth_time']) && !is_int($proof['auth_time']))) {
            throw self::failure('identity_changed');
        }
        return ['subject' => $proof['subject'], 'auth_time' => $proof['auth_time'] ?? null, 'started_at' => $proof['started_at']];
    }
}
