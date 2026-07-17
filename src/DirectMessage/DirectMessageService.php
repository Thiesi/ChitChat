<?php

declare(strict_types=1);
namespace ChitChat\DirectMessage;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use PDO;
use RuntimeException;
use Throwable;

final class DirectMessageService
{
    private readonly UserRepository $users;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    /** @return list<array{id:int, username:string}> */
    public function searchUsers(AuthenticatedUser $actor, string $search, int $limit = 20): array
    {
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
WHERE id <> :actor_id
  AND lower(username) LIKE :pattern
ORDER BY lower(username), id
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message user search.');
        }
        $statement->bindValue(':actor_id', $actor->id, PDO::PARAM_INT);
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
     * @return list<array{
     *   user:array{id:int, username:string},
     *   last_message:array{id:int, sender_id:int, body:string, created_at:string, outgoing:bool},
     *   unread_count:int
     * }>
     */
    public function conversations(AuthenticatedUser $actor, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
WITH participant_messages AS (
    SELECT dm.*,
           CASE
               WHEN dm.sender_user_id = :actor_case THEN dm.recipient_user_id
               ELSE dm.sender_user_id
           END AS other_user_id,
           CASE
               WHEN dm.recipient_user_id = :actor_unread AND dm.recipient_read_at IS NULL THEN 1
               ELSE 0
           END AS unread_flag
    FROM direct_messages dm
    WHERE dm.sender_user_id = :actor_sender
       OR dm.recipient_user_id = :actor_recipient
),
peer_summary AS (
    SELECT other_user_id,
           MAX(id) AS last_message_id,
           SUM(unread_flag)::integer AS unread_count
    FROM participant_messages
    GROUP BY other_user_id
)
SELECT u.id AS other_user_id,
       u.username AS other_username,
       dm.id AS last_message_id,
       dm.sender_user_id AS last_sender_id,
       dm.body AS last_body,
       dm.created_at AS last_created_at,
       ps.unread_count
FROM peer_summary ps
JOIN users u ON u.id = ps.other_user_id
JOIN direct_messages dm ON dm.id = ps.last_message_id
ORDER BY ps.last_message_id DESC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message conversation list.');
        }
        $statement->bindValue(':actor_case', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':actor_unread', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':actor_sender', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':actor_recipient', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'user' => [
                    'id' => (int) $row['other_user_id'],
                    'username' => (string) $row['other_username'],
                ],
                'last_message' => [
                    'id' => (int) $row['last_message_id'],
                    'sender_id' => (int) $row['last_sender_id'],
                    'body' => (string) $row['last_body'],
                    'created_at' => (string) $row['last_created_at'],
                    'outgoing' => (int) $row['last_sender_id'] === $actor->id,
                ],
                'unread_count' => (int) $row['unread_count'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool
     * }>
     */
    public function history(
        AuthenticatedUser $actor,
        int $otherUserId,
        ?int $beforeId = null,
        int $limit = 50,
    ): array {
        $this->requireOtherUser($actor, $otherUserId);
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
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
        dm.sender_user_id = :actor_sender
        AND dm.recipient_user_id = :other_recipient
      )
   OR (
        dm.sender_user_id = :other_sender
        AND dm.recipient_user_id = :actor_recipient
      )
SQL;
        if ($beforeId !== null) {
            $sql = 'SELECT * FROM (' . $sql . ') conversation WHERE conversation.id < :before_id';
        }
        $sql .= "\nORDER BY id DESC\nLIMIT :limit";

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message history.');
        }
        $statement->bindValue(':actor_sender', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':other_recipient', $otherUserId, PDO::PARAM_INT);
        $statement->bindValue(':other_sender', $otherUserId, PDO::PARAM_INT);
        $statement->bindValue(':actor_recipient', $actor->id, PDO::PARAM_INT);
        if ($beforeId !== null) {
            $statement->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $messages = [];
        foreach (array_reverse($statement->fetchAll()) as $row) {
            if (is_array($row)) {
                $messages[] = $this->hydrate($row, $actor->id);
            }
        }

        return $messages;
    }

    /**
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool
     * }
     */
    public function send(AuthenticatedUser $actor, int $recipientUserId, string $bodyInput): array
    {
        $this->requireOtherUser($actor, $recipientUserId);
        $body = trim($bodyInput);
        if ($body === '') {
            throw new ApiException(400, 'empty_message', 'Direct message body cannot be empty.');
        }
        if (mb_strlen($body, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Direct message body must not exceed 4000 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body)
VALUES (:sender_user_id, :recipient_user_id, :body)
RETURNING id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare direct-message send.');
            }
            $statement->execute([
                'sender_user_id' => $actor->id,
                'recipient_user_id' => $recipientUserId,
                'body' => $body,
            ]);
            $messageIdValue = $statement->fetchColumn();
            if ($messageIdValue === false) {
                throw new RuntimeException('Direct-message send did not return an ID.');
            }
            $messageId = (int) $messageIdValue;
            $senderMessage = $this->messageById($messageId, $actor->id);
            $recipientMessage = $this->messageById($messageId, $recipientUserId);

            $this->events->publish(
                type: 'direct_message',
                payload: ['message' => $senderMessage],
                targetUserId: $actor->id,
                actorUserId: $actor->id,
            );
            $this->events->publish(
                type: 'direct_message',
                payload: ['message' => $recipientMessage],
                targetUserId: $recipientUserId,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();

            return $senderMessage;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markRead(AuthenticatedUser $actor, int $otherUserId): int
    {
        $this->requireOtherUser($actor, $otherUserId);
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE direct_messages
SET recipient_read_at = NOW()
WHERE sender_user_id = :sender_user_id
  AND recipient_user_id = :recipient_user_id
  AND recipient_read_at IS NULL
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message read acknowledgement.');
        }
        $statement->execute([
            'sender_user_id' => $otherUserId,
            'recipient_user_id' => $actor->id,
        ]);

        return $statement->rowCount();
    }

    private function requireOtherUser(AuthenticatedUser $actor, int $otherUserId): AuthenticatedUser
    {
        if ($otherUserId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        if ($otherUserId === $actor->id) {
            throw new ApiException(400, 'direct_message_self_forbidden', 'You cannot send direct messages to yourself.');
        }
        $other = $this->users->findAuthenticatedById($otherUserId);
        if ($other === null) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
        }

        return $other;
    }

    /**
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool
     * }
     */
    private function messageById(int $messageId, int $viewerUserId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
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
WHERE dm.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Direct message could not be reloaded.');
        }

        return $this->hydrate($row, $viewerUserId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool
     * }
     */
    private function hydrate(array $row, int $viewerUserId): array
    {
        return [
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
            'outgoing' => (int) $row['sender_user_id'] === $viewerUserId,
        ];
    }
}
