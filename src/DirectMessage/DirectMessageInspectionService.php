<?php

declare(strict_types=1);
namespace ChitChat\DirectMessage;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

final class DirectMessageInspectionService
{
    private readonly UserRepository $users;
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
    }

    /** @return list<array{id:int, username:string}> */
    public function searchUsers(AuthenticatedUser $actor, string $search, int $limit = 20): array
    {
        $this->requireInspectionAccess($actor);
        $search = trim($search);
        if (mb_strlen($search, 'UTF-8') < 2 || mb_strlen($search, 'UTF-8') > 32) {
            throw new ApiException(400, 'validation_error', 'search must contain 2-32 characters.');
        }
        if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $search) !== 1) {
            throw new ApiException(400, 'validation_error', 'search contains unsupported characters.');
        }
        if ($limit < 1 || $limit > 50) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 50.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, username
FROM users
WHERE lower(username) LIKE :pattern
ORDER BY lower(username), id
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare inspection user search.');
        }
        $statement->bindValue(':pattern', strtolower($search) . '%');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   user_a:array{id:int, username:string},
     *   user_b:array{id:int, username:string},
     *   messages:list<array{
     *     id:int,
     *     sender:array{id:int, username:string},
     *     recipient:array{id:int, username:string},
     *     body:string,
     *     read_at:?string,
     *     created_at:string
     *   }>
     * }
     */
    public function inspect(
        AuthenticatedUser $actor,
        int $userAId,
        int $userBId,
        string $reasonInput,
        ?int $beforeId,
        int $limit,
        string $ipAddress,
    ): array {
        $this->requireInspectionAccess($actor);
        if ($userAId < 1 || $userBId < 1) {
            throw new ApiException(400, 'validation_error', 'Both user IDs must be positive.');
        }
        if ($userAId === $userBId) {
            throw new ApiException(400, 'inspection_pair_invalid', 'Select two different users.');
        }
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $reason = trim($reasonInput);
        $reasonLength = mb_strlen($reason, 'UTF-8');
        if ($reasonLength < 3 || $reasonLength > 500) {
            throw new ApiException(400, 'inspection_reason_required', 'Inspection reason must contain 3-500 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $userA = $this->users->findAuthenticatedById($userAId);
            $userB = $this->users->findAuthenticatedById($userBId);
            if ($userA === null || $userB === null) {
                throw new ApiException(404, 'user_not_found', 'One or both selected users were not found.');
            }

            $sql = <<<'SQL'
SELECT dm.id,
       dm.sender_user_id,
       sender.username AS sender_username,
       dm.recipient_user_id,
       recipient.username AS recipient_username,
       dm.body,
       dm.recipient_read_at,
       dm.created_at
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users recipient ON recipient.id = dm.recipient_user_id
WHERE (
        dm.sender_user_id = :user_a_sender
        AND dm.recipient_user_id = :user_b_recipient
      )
   OR (
        dm.sender_user_id = :user_b_sender
        AND dm.recipient_user_id = :user_a_recipient
      )
SQL;
            if ($beforeId !== null) {
                $sql = 'SELECT * FROM (' . $sql . ') inspected_conversation WHERE inspected_conversation.id < :before_id';
            }
            $sql .= "\nORDER BY id DESC\nLIMIT :limit";

            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare direct-message inspection.');
            }
            $statement->bindValue(':user_a_sender', $userAId, PDO::PARAM_INT);
            $statement->bindValue(':user_b_recipient', $userBId, PDO::PARAM_INT);
            $statement->bindValue(':user_b_sender', $userBId, PDO::PARAM_INT);
            $statement->bindValue(':user_a_recipient', $userAId, PDO::PARAM_INT);
            if ($beforeId !== null) {
                $statement->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
            }
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            $messages = [];
            foreach (array_reverse($statement->fetchAll()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $messages[] = [
                    'id' => (int) $row['id'],
                    'sender' => [
                        'id' => (int) $row['sender_user_id'],
                        'username' => (string) $row['sender_username'],
                    ],
                    'recipient' => [
                        'id' => (int) $row['recipient_user_id'],
                        'username' => (string) $row['recipient_username'],
                    ],
                    'body' => (string) $row['body'],
                    'read_at' => $row['recipient_read_at'] === null ? null : (string) $row['recipient_read_at'],
                    'created_at' => (string) $row['created_at'],
                ];
            }

            $ids = array_column($messages, 'id');
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'admin.direct_messages_inspected',
                subjectType: 'direct_message_conversation',
                subjectId: $userAId . ':' . $userBId,
                metadata: [
                    'user_a_id' => $userAId,
                    'user_a_username' => $userA->username,
                    'user_b_id' => $userBId,
                    'user_b_username' => $userB->username,
                    'reason' => $reason,
                    'before_id' => $beforeId,
                    'limit' => $limit,
                    'returned_count' => count($messages),
                    'oldest_message_id' => $ids === [] ? null : min($ids),
                    'newest_message_id' => $ids === [] ? null : max($ids),
                ],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            return [
                'user_a' => ['id' => $userA->id, 'username' => $userA->username],
                'user_b' => ['id' => $userB->id, 'username' => $userB->username],
                'messages' => $messages,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requireInspectionAccess(AuthenticatedUser $actor): void
    {
        if (!$this->config->directMessageInspectionEnabled) {
            throw new ApiException(403, 'dm_inspection_disabled', 'Administrative direct-message inspection is disabled.');
        }

        $allowed = $this->config->directMessageInspectionRole === 'super_admin'
            ? $actor->hasRole('super_admin')
            : $actor->canManageUsers();
        if (!$allowed) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to inspect direct messages.');
        }
    }
}
