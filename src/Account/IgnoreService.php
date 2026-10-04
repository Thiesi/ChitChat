<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * Ignoring someone in rooms: a private, per-account choice. Their room
 * messages are collapsed for the person ignoring them, and their mentions
 * and pings no longer notify. The ignored person is never told, and
 * nothing changes for anyone else.
 */
final class IgnoreService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function setIgnored(AuthenticatedUser $actor, int $userId, bool $ignored): void
    {
        if ($userId === $actor->id) {
            throw new ApiException(400, 'validation_error', 'You cannot ignore yourself.');
        }
        if ($ignored) {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_ignores (user_id, ignored_user_id)
SELECT :user_id, id FROM users WHERE id = :ignored_user_id AND account_state <> 'closed'
ON CONFLICT (user_id, ignored_user_id) DO NOTHING
SQL);
        } else {
            $statement = $this->pdo->prepare(
                'DELETE FROM user_ignores WHERE user_id = :user_id AND ignored_user_id = :ignored_user_id',
            );
        }
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ignore update.');
        }
        $statement->execute(['user_id' => $actor->id, 'ignored_user_id' => $userId]);
        if ($ignored && $statement->rowCount() === 0 && !$this->isIgnoring($actor->id, $userId)) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
        }
    }

    /** @return list<int> */
    public function ignoredBy(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ignored_user_id FROM user_ignores WHERE user_id = :user_id ORDER BY ignored_user_id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ignore list.');
        }
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function isIgnoring(int $userId, int $senderId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM user_ignores WHERE user_id = :user_id AND ignored_user_id = :ignored_user_id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ignore lookup.');
        }
        $statement->execute(['user_id' => $userId, 'ignored_user_id' => $senderId]);

        return $statement->fetchColumn() !== false;
    }
}
