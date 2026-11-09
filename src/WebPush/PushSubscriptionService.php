<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class PushSubscriptionService
{
    public const MAX_SUBSCRIPTIONS = 20;
    private readonly PushDestinationGuard $guard;

    public function __construct(private readonly PDO $pdo, ?PushDestinationGuard $guard = null,
        private readonly ?RateLimiter $limiter = null)
    {
        if ($guard === null) {
            $config = Config::fromEnvironment();
            $guard = new PushDestinationGuard(new ReactPushDnsResolver(resolvConf: $config->webPushDnsResolvConf),
                new PushAddressPolicy($config->webPushPrivateProviders));
        }
        $this->guard = $guard;
    }

    /** @return array{subscribed:true,id:int,binding:string} */
    public function subscribe(AuthenticatedUser $actor, string $endpoint, string $p256dhKey,
        #[\SensitiveParameter] string $authKey, ?string $userAgent, string $sessionBindingHash,
        ?int $replacesId = null, ?string $deviceLabel = null): array
    {
        try {
            $endpoint = PushEndpoint::parse($endpoint)->url;
            $keys = PushKeyMaterial::fromInput($p256dhKey, $authKey);
            if (preg_match('/\A[0-9a-f]{64}\z/D', $sessionBindingHash) !== 1
                || ($replacesId !== null && $replacesId < 1)
                || ($deviceLabel !== null && (mb_strlen($deviceLabel, 'UTF-8') > 64 || preg_match('/[\x00-\x1f\x7f]/', $deviceLabel)))) {
                throw new InvalidArgumentException('Invalid push registration metadata.');
            }
        } catch (ApiException | InvalidArgumentException) {
            $this->managementAttempt($actor);
            throw new ApiException(400, 'validation_error', 'Provide a valid HTTPS push endpoint, P-256 key, 16-byte auth secret and registration metadata.');
        }
        $userAgent = $userAgent === null ? null : mb_substr(trim($userAgent), 0, 256, 'UTF-8');
        $deviceLabel = $deviceLabel === null ? null : trim($deviceLabel);
        // Lock, classify and refresh atomically. No DNS and no management charge for
        // an identical registration by this same authenticated login session.
        if ($replacesId === null) {
            $this->pdo->beginTransaction();
            try {
                $this->lockActor($actor, $sessionBindingHash);
                $row = $this->query('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint FOR UPDATE',
                    ['endpoint' => $endpoint])->fetch();
                if (is_array($row) && (int) $row['user_id'] === $actor->id
                    && (int) $row['session_version'] === $actor->sessionVersion
                    && $row['session_binding_hash'] === $sessionBindingHash
                    && $row['p256dh_key'] === $keys->publicKey && $row['auth_key'] === $keys->auth
                    && ($deviceLabel === null || $row['device_label'] === $deviceLabel)) {
                    $this->query('UPDATE push_subscriptions SET last_used_at = clock_timestamp(), user_agent = :agent WHERE id = :id',
                        ['agent' => $userAgent, 'id' => $row['id']]);
                    $this->pdo->commit();
                    return ['subscribed' => true, 'id' => (int) $row['id'], 'binding' => (string) $row['delivery_binding']];
                }
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
        // Attempts survive validation failure and mutation rollback. Never charge a
        // counter inside the transaction which might roll it back.
        $this->managementAttempt($actor);
        try {
            $this->guard->validate($endpoint);
        } catch (PushDnsException $exception) {
            throw new ApiException($exception->retryable ? 503 : 400,
                $exception->retryable ? 'push_provider_unavailable' : 'validation_error',
                $exception->retryable ? 'Push provider DNS is temporarily unavailable.' : 'Push provider has no usable addresses.');
        } catch (InvalidArgumentException) {
            throw new ApiException(400, 'validation_error', 'Push provider destination is not permitted.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockActor($actor, $sessionBindingHash);
            // Endpoint uniqueness is global. Never transfer ownership, even if the
            // caller knows another account's endpoint and encryption keys.
            $row = $this->query('SELECT * FROM push_subscriptions WHERE endpoint = :endpoint FOR UPDATE',
                ['endpoint' => $endpoint])->fetch();
            if (is_array($row) && (int) $row['user_id'] !== $actor->id) {
                throw new ApiException(409, 'push_subscription_conflict', 'This push endpoint is already registered to another account.');
            }
            if ($replacesId !== null) {
                $owned = $this->query('SELECT id FROM push_subscriptions WHERE id = :id AND user_id = :user FOR UPDATE',
                    ['id' => $replacesId, 'user' => $actor->id])->fetchColumn();
                if ($owned === false) {
                    $oldExists = $this->query('SELECT id FROM push_subscriptions WHERE id = :id', ['id' => $replacesId])->fetchColumn();
                    if ($oldExists === false && is_array($row) && (int) $row['user_id'] === $actor->id
                        && (int) $row['session_version'] === $actor->sessionVersion
                        && $row['session_binding_hash'] === $sessionBindingHash
                        && $row['p256dh_key'] === $keys->publicKey && $row['auth_key'] === $keys->auth
                        && ($deviceLabel === null || $row['device_label'] === $deviceLabel)) {
                        // The old row was removed by an already-successful replacement
                        // whose response was lost. Return exactly that generation.
                        $this->pdo->commit();
                        return ['subscribed' => true, 'id' => (int) $row['id'], 'binding' => (string) $row['delivery_binding']];
                    }
                    throw new ApiException(404, 'not_found', 'The push device was not found.');
                }
                if (!is_array($row) || (int) $row['id'] !== $replacesId) {
                    $this->query('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :user',
                        ['id' => $replacesId, 'user' => $actor->id]);
                }
            }
            if (!is_array($row) && (int) $this->query('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :user',
                ['user' => $actor->id])->fetchColumn() >= self::MAX_SUBSCRIPTIONS) {
                throw new ApiException(409, 'push_subscription_limit', 'Remove an existing push device before registering another.');
            }
            $binding = bin2hex(random_bytes(32));
            $parameters = ['user' => $actor->id, 'endpoint' => $endpoint, 'key' => $keys->publicKey, 'auth' => $keys->auth,
                'agent' => $userAgent, 'version' => $actor->sessionVersion, 'session' => $sessionBindingHash, 'binding' => $binding,
                'label' => $deviceLabel];
            if (is_array($row)) {
                $parameters['id'] = $row['id'];
                $id = $this->query(<<<'SQL'
UPDATE push_subscriptions SET endpoint = :endpoint, p256dh_key = :key, auth_key = :auth,
    user_agent = :agent, session_version = :version, session_binding_hash = :session,
    delivery_binding = :binding, device_label = COALESCE(:label, device_label),
    generation = generation + 1, generation_started_at = clock_timestamp(), last_used_at = clock_timestamp()
WHERE id = :id AND user_id = :user RETURNING id
SQL, $parameters)->fetchColumn();
            } else {
                $id = $this->query(<<<'SQL'
INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent, session_version,
    session_binding_hash, delivery_binding, device_label, last_used_at)
VALUES (:user, :endpoint, :key, :auth, :agent, :version, :session, :binding, :label, clock_timestamp()) RETURNING id
SQL, $parameters)->fetchColumn();
            }
            $this->pdo->commit();
            return ['subscribed' => true, 'id' => (int) $id, 'binding' => $binding];
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            if ($exception instanceof PDOException && $exception->getCode() === '23505') {
                throw new ApiException(409, 'push_subscription_conflict', 'This push endpoint is already registered.');
            }
            throw $exception;
        }
    }

    public function unsubscribe(AuthenticatedUser $actor, string $endpoint): void
    {
        try {
            $endpoint = PushEndpoint::parse($endpoint)->url;
        } catch (InvalidArgumentException) {
            throw new ApiException(400, 'validation_error', 'Invalid push endpoint.');
        }
        $this->query('DELETE FROM push_subscriptions WHERE user_id = :user AND endpoint = :endpoint',
            ['user' => $actor->id, 'endpoint' => $endpoint]);
    }

    public function revoke(AuthenticatedUser $actor, int $subscriptionId): void
    {
        if ($subscriptionId < 1) {
            throw new ApiException(400, 'validation_error', 'id must be positive.');
        }
        $this->query('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :user',
            ['id' => $subscriptionId, 'user' => $actor->id]);
    }

    public function logout(AuthenticatedUser $actor, string $sessionBindingHash): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => $actor->id]);
            $this->query(<<<'SQL'
DELETE FROM push_subscriptions WHERE user_id = :user
 AND (session_binding_hash = :binding OR session_binding_hash IS NULL OR session_version <> :version)
SQL, ['user' => $actor->id, 'binding' => $sessionBindingHash, 'version' => $actor->sessionVersion]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return list<array{id:int,user_agent:?string,device_label:?string,current_session:bool,created_at:string,last_used_at:?string}> */
    public function list(AuthenticatedUser $actor, ?string $sessionBindingHash = null): array
    {
        $rows = $this->query(<<<'SQL'
SELECT id, user_agent, device_label, session_binding_hash, created_at, last_used_at
FROM push_subscriptions WHERE user_id = :user AND session_version = :version ORDER BY created_at DESC, id DESC
SQL, ['user' => $actor->id, 'version' => $actor->sessionVersion])->fetchAll();
        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'user_agent' => $row['user_agent'] === null ? null : (string) $row['user_agent'],
            'device_label' => $row['device_label'] === null ? null : (string) $row['device_label'],
            'current_session' => $sessionBindingHash !== null && $row['session_binding_hash'] === $sessionBindingHash,
            'created_at' => (string) $row['created_at'], 'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
        ], $rows));
    }

    private function managementAttempt(AuthenticatedUser $actor): void
    {
        $this->limiter?->consume('push_subscription_management', 'user:' . $actor->id);
    }

    private function lockActor(AuthenticatedUser $actor, string $sessionBindingHash): void
    {
        $row = $this->query('SELECT account_state, account_kind, session_version FROM users WHERE id = :id FOR NO KEY UPDATE',
            ['id' => $actor->id])->fetch();
        if (!is_array($row) || $actor->guest || $row['account_kind'] === 'guest' || $row['account_state'] !== 'active'
            || (int) $row['session_version'] !== $actor->sessionVersion || (new UserRepository($this->pdo))->activeBan($actor->id) !== null) {
            throw new ApiException(401, 'authentication_required', 'Sign in again before registering push.');
        }
        (new \ChitChat\Auth\LoginSessionRepository($this->pdo))->requireBound($actor, $sessionBindingHash);
        // Obsolete login generations must not consume the account's device allowance.
        $this->query('DELETE FROM push_subscriptions WHERE user_id = :user AND session_version <> :version',
            ['user' => $actor->id, 'version' => $actor->sessionVersion]);
    }

    /** @param array<string,mixed> $parameters */
    private function query(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}