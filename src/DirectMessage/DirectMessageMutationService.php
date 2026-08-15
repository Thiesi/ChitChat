<?php

declare(strict_types=1);

namespace ChitChat\DirectMessage;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Reactions\ReactionHydrator;
use ChitChat\Realtime\EventRepository;
use PDO;
use RuntimeException;
use Throwable;

final class DirectMessageMutationService
{
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly DirectMessageBlockService $blocks;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->blocks = new DirectMessageBlockService($pdo);
    }

    /**
     * @param list<int> $messageIds
     * @return list<array{
     *   id:int,
     *   body:?string,
     *   edited_at:?string,
     *   deleted:bool,
     *   can_edit:bool,
     *   can_delete:bool,
     *   mentions:list<array{user_id:int, username:string}>,
     *   reactions:list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}>
     * }>
     */
    public function metadata(AuthenticatedUser $actor, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        [$placeholders, $parameters] = $this->idParameters($messageIds);
        $parameters['actor_sender'] = $actor->id;
        $parameters['actor_recipient'] = $actor->id;
        $parameters['viewer_user_id'] = $actor->id;
        $reactionsSubquery = ReactionHydrator::correlatedSubquery(
            'direct_message_reactions',
            'dm.id',
            ':viewer_user_id',
        );
        $statement = $this->pdo->prepare(<<<SQL
SELECT dm.id,
       dm.sender_user_id,
       dm.recipient_user_id,
       dm.body,
       dm.edited_at,
       dm.deleted_at,
       NOT EXISTS (
           SELECT 1
           FROM direct_message_blocks b
           WHERE (
                   b.blocker_user_id = dm.sender_user_id
                   AND b.blocked_user_id = dm.recipient_user_id
                 )
              OR (
                   b.blocker_user_id = dm.recipient_user_id
                   AND b.blocked_user_id = dm.sender_user_id
                 )
       ) AS messaging_available,
       (
           SELECT json_agg(
               json_build_object(
                   'user_id', dmm.mentioned_user_id,
                   'username', mu.username
               )
               ORDER BY dmm.id
           )
           FROM direct_message_mentions dmm
           JOIN users mu ON mu.id = dmm.mentioned_user_id
           WHERE dmm.message_id = dm.id
       ) AS mentions_json,
       {$reactionsSubquery} AS reactions_json
FROM direct_messages dm
WHERE dm.id IN ({$placeholders})
  AND (
       dm.sender_user_id = :actor_sender
       OR dm.recipient_user_id = :actor_recipient
  )
ORDER BY dm.id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message mutation metadata.');
        }
        $statement->execute($parameters);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $deleted = $row['deleted_at'] !== null;
            $owned = (int) $row['sender_user_id'] === $actor->id;
            $available = $this->databaseBoolean($row['messaging_available'] ?? false);
            $result[] = [
                'id' => (int) $row['id'],
                'body' => $deleted ? null : (string) $row['body'],
                'edited_at' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
                'deleted' => $deleted,
                'can_edit' => $owned && $available && !$deleted,
                'can_delete' => $owned && !$deleted,
                'mentions' => $deleted ? [] : $this->hydrateMentions($row),
                'reactions' => ReactionHydrator::hydrateJson($row['reactions_json'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{user_id:int, username:string}>
     */
    private function hydrateMentions(array $row): array
    {
        $encoded = $row['mentions_json'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $mentions = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $mentions[] = [
                'user_id' => (int) $entry['user_id'],
                'username' => (string) $entry['username'],
            ];
        }

        return $mentions;
    }

    /**
     * @return array{id:int, body:?string, edited_at:?string, deleted:bool, can_edit:bool, can_delete:bool}
     */
    public function edit(
        AuthenticatedUser $actor,
        int $messageId,
        string $bodyInput,
        string $ipAddress,
    ): array {
        $body = trim($bodyInput);
        if ($body === '') {
            throw new ApiException(400, 'empty_message', 'Direct message body cannot be empty.');
        }
        if (mb_strlen($body, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Direct message body must not exceed 4000 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->message($messageId);
            $this->requireAuthor($actor, $row);
            $recipientId = (int) $row['recipient_user_id'];
            $this->blocks->lockPair($actor->id, $recipientId);
            $this->blocks->requireMessagingAvailable($actor, $recipientId);
            $row = $this->message($messageId, true);
            $this->requireAuthor($actor, $row);
            if ((string) $row['body'] === $body) {
                throw new ApiException(409, 'message_unchanged', 'The edited direct message is unchanged.');
            }

            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE direct_messages
SET body = :body,
    edited_at = NOW(),
    edited_by = :edited_by
WHERE id = :id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare direct-message edit.');
            }
            $statement->execute([
                'body' => $body,
                'edited_by' => $actor->id,
                'id' => $messageId,
            ]);

            $this->audit->log(
                $actor->id,
                'direct_message.edited_by_author',
                'direct_message',
                (string) $messageId,
                ['recipient_user_id' => $recipientId],
                $ipAddress,
            );
            $this->publishChanged($messageId, $actor->id, $recipientId);
            $this->pdo->commit();

            return $this->metadata($actor, [$messageId])[0];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{id:int, body:?string, edited_at:?string, deleted:bool, can_edit:bool, can_delete:bool}
     */
    public function deleteOwn(
        AuthenticatedUser $actor,
        int $messageId,
        string $ipAddress,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $row = $this->message($messageId);
            $this->requireAuthor($actor, $row);
            $recipientId = (int) $row['recipient_user_id'];
            $this->blocks->lockPair($actor->id, $recipientId);
            $row = $this->message($messageId, true);
            $this->requireAuthor($actor, $row);

            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE direct_messages
SET body = NULL,
    deleted_at = NOW(),
    deleted_by = :deleted_by
WHERE id = :id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare author direct-message deletion.');
            }
            $statement->execute([
                'deleted_by' => $actor->id,
                'id' => $messageId,
            ]);

            $attachmentStatement = $this->pdo->prepare(<<<'SQL'
UPDATE direct_message_attachments
SET deleted_at = NOW(),
    deleted_by = :deleted_by
WHERE direct_message_id = :message_id
  AND deleted_at IS NULL
SQL);
            if ($attachmentStatement === false) {
                throw new RuntimeException('Unable to prepare direct-message attachment deletion.');
            }
            $attachmentStatement->execute([
                'deleted_by' => $actor->id,
                'message_id' => $messageId,
            ]);

            $this->audit->log(
                $actor->id,
                'direct_message.deleted_by_author',
                'direct_message',
                (string) $messageId,
                ['recipient_user_id' => $recipientId],
                $ipAddress,
            );
            $this->publishChanged($messageId, $actor->id, $recipientId);
            $this->pdo->commit();

            return $this->metadata($actor, [$messageId])[0];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function message(int $messageId, bool $forUpdate = false): array
    {
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }
        $locking = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(<<<SQL
SELECT id,
       sender_user_id,
       recipient_user_id,
       body,
       recipient_read_at,
       edited_at,
       deleted_at
FROM direct_messages
WHERE id = :id{$locking}
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message mutation lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Direct message not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function requireAuthor(AuthenticatedUser $actor, array $row): void
    {
        if ($row['deleted_at'] !== null) {
            throw new ApiException(409, 'message_already_deleted', 'Direct message is already deleted.');
        }
        if ((int) $row['sender_user_id'] !== $actor->id) {
            throw new ApiException(403, 'message_author_required', 'Only the sender may change this direct message.');
        }
    }

    private function publishChanged(int $messageId, int $senderId, int $recipientId): void
    {
        $senderMessage = $this->eventMessage($messageId, $senderId);
        $recipientMessage = $this->eventMessage($messageId, $recipientId);
        $this->events->publish(
            type: 'direct_message',
            payload: ['message' => $senderMessage],
            targetUserId: $senderId,
            actorUserId: $senderId,
        );
        $this->events->publish(
            type: 'direct_message',
            payload: ['message' => $recipientMessage],
            targetUserId: $recipientId,
            actorUserId: $senderId,
        );
    }

    /**
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:?string,
     *   read_at:?string,
     *   edited_at:?string,
     *   deleted:bool,
     *   created_at:string,
     *   outgoing:bool
     * }
     */
    private function eventMessage(int $messageId, int $viewerUserId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT dm.id,
       dm.sender_user_id,
       sender.username AS sender_username,
       dm.recipient_user_id,
       recipient.username AS recipient_username,
       dm.body,
       dm.recipient_read_at,
       dm.edited_at,
       dm.deleted_at,
       dm.created_at
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users recipient ON recipient.id = dm.recipient_user_id
WHERE dm.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare changed direct-message event.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Changed direct message could not be reloaded.');
        }

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
            'body' => $row['deleted_at'] === null ? (string) $row['body'] : null,
            'read_at' => $row['recipient_read_at'] === null ? null : (string) $row['recipient_read_at'],
            'edited_at' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
            'deleted' => $row['deleted_at'] !== null,
            'created_at' => (string) $row['created_at'],
            'outgoing' => (int) $row['sender_user_id'] === $viewerUserId,
        ];
    }

    /**
     * @param list<int> $ids
     * @return array{string, array<string, int>}
     */
    private function idParameters(array $ids): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $name = 'message_id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        return [implode(', ', $placeholders), $parameters];
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
