<?php

declare(strict_types=1);
namespace ChitChat\DirectMessage;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

final class DirectMessageBlockService
{
    private readonly UserRepository $users;

    public function __construct(private readonly PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
    }

    /** @return array{blocked_by_me:bool, messaging_available:bool} */
    public function relationship(AuthenticatedUser $actor, int $otherUserId): array
    {
        $this->requireOtherUser($actor, $otherUserId);

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT
    EXISTS (
        SELECT 1
        FROM direct_message_blocks
        WHERE blocker_user_id = :actor_blocker
          AND blocked_user_id = :other_blocked
    ) AS blocked_by_me,
    NOT EXISTS (
        SELECT 1
        FROM direct_message_blocks
        WHERE (
                blocker_user_id = :actor_first
                AND blocked_user_id = :other_first
              )
           OR (
                blocker_user_id = :other_second
                AND blocked_user_id = :actor_second
              )
    ) AS messaging_available
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message relationship lookup.');
        }
        $statement->execute([
            'actor_blocker' => $actor->id,
            'other_blocked' => $otherUserId,
            'actor_first' => $actor->id,
            'other_first' => $otherUserId,
            'other_second' => $otherUserId,
            'actor_second' => $actor->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Direct-message relationship could not be loaded.');
        }

        return [
            'blocked_by_me' => $this->databaseBoolean($row['blocked_by_me'] ?? false),
            'messaging_available' => $this->databaseBoolean($row['messaging_available'] ?? false),
        ];
    }

    /** @return array{blocked_by_me:bool, messaging_available:bool} */
    public function block(AuthenticatedUser $actor, int $otherUserId): array
    {
        $this->requireOtherUser($actor, $otherUserId);
        $this->pdo->beginTransaction();
        try {
            $this->lockPair($actor->id, $otherUserId);
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_message_blocks (blocker_user_id, blocked_user_id)
VALUES (:blocker_user_id, :blocked_user_id)
ON CONFLICT (blocker_user_id, blocked_user_id) DO NOTHING
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare direct-message block.');
            }
            $statement->execute([
                'blocker_user_id' => $actor->id,
                'blocked_user_id' => $otherUserId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->relationship($actor, $otherUserId);
    }

    /** @return array{blocked_by_me:bool, messaging_available:bool} */
    public function unblock(AuthenticatedUser $actor, int $otherUserId): array
    {
        $this->requireOtherUser($actor, $otherUserId);
        $this->pdo->beginTransaction();
        try {
            $this->lockPair($actor->id, $otherUserId);
            $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM direct_message_blocks
WHERE blocker_user_id = :blocker_user_id
  AND blocked_user_id = :blocked_user_id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare direct-message unblock.');
            }
            $statement->execute([
                'blocker_user_id' => $actor->id,
                'blocked_user_id' => $otherUserId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->relationship($actor, $otherUserId);
    }

    public function requireMessagingAvailable(AuthenticatedUser $actor, int $otherUserId): void
    {
        if (!$this->relationship($actor, $otherUserId)['messaging_available']) {
            throw new ApiException(403, 'direct_message_unavailable', 'Direct messaging is unavailable for this user.');
        }
    }

    public function lockPair(int $firstUserId, int $secondUserId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Direct-message pair locks require an active transaction.');
        }
        $pair = min($firstUserId, $secondUserId) . ':' . max($firstUserId, $secondUserId);
        $statement = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:pair, 0))');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message pair lock.');
        }
        $statement->execute(['pair' => $pair]);
    }

    private function requireOtherUser(AuthenticatedUser $actor, int $otherUserId): void
    {
        if ($otherUserId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        if ($otherUserId === $actor->id) {
            throw new ApiException(400, 'direct_message_self_forbidden', 'You cannot manage direct-message blocking for yourself.');
        }
        if ($this->users->findAuthenticatedById($otherUserId) === null) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
