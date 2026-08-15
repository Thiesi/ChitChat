<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

final class PushSubscriptionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function subscribe(
        AuthenticatedUser $actor,
        string $endpoint,
        string $p256dhKey,
        string $authKey,
        ?string $userAgent,
    ): void {
        $endpoint = trim($endpoint);
        $p256dhKey = trim($p256dhKey);
        $authKey = trim($authKey);
        if ($endpoint === '' || mb_strlen($endpoint, 'UTF-8') > 2048) {
            throw new ApiException(400, 'validation_error', 'endpoint must be 1-2048 characters.');
        }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($endpoint), 'https://')) {
            throw new ApiException(400, 'validation_error', 'endpoint must be an https URL.');
        }
        if ($p256dhKey === '' || $authKey === '') {
            throw new ApiException(400, 'validation_error', 'p256dh and auth keys are required.');
        }
        $userAgent = $userAgent === null ? null : mb_substr(trim($userAgent), 0, 256, 'UTF-8');
        if ($userAgent === '') {
            $userAgent = null;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent, last_used_at)
VALUES (:user_id, :endpoint, :p256dh_key, :auth_key, :user_agent, NOW())
ON CONFLICT (endpoint) DO UPDATE SET
    user_id = EXCLUDED.user_id,
    p256dh_key = EXCLUDED.p256dh_key,
    auth_key = EXCLUDED.auth_key,
    user_agent = EXCLUDED.user_agent,
    last_used_at = NOW()
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push subscription upsert.');
        }
        $statement->execute([
            'user_id' => $actor->id,
            'endpoint' => $endpoint,
            'p256dh_key' => $p256dhKey,
            'auth_key' => $authKey,
            'user_agent' => $userAgent,
        ]);
    }

    public function unsubscribe(AuthenticatedUser $actor, string $endpoint): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint = :endpoint',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push subscription removal.');
        }
        $statement->execute(['user_id' => $actor->id, 'endpoint' => $endpoint]);
    }

    public function revoke(AuthenticatedUser $actor, int $subscriptionId): void
    {
        if ($subscriptionId < 1) {
            throw new ApiException(400, 'validation_error', 'id must be positive.');
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM push_subscriptions WHERE id = :id AND user_id = :user_id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push subscription revocation.');
        }
        $statement->execute(['id' => $subscriptionId, 'user_id' => $actor->id]);
    }

    /** @return list<array{id:int, user_agent:?string, created_at:string, last_used_at:?string}> */
    public function list(AuthenticatedUser $actor): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, user_agent, created_at, last_used_at
FROM push_subscriptions
WHERE user_id = :user_id
ORDER BY created_at DESC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push subscription list.');
        }
        $statement->execute(['user_id' => $actor->id]);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'user_agent' => $row['user_agent'] === null ? null : (string) $row['user_agent'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            ];
        }

        return $result;
    }
}
