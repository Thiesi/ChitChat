<?php

declare(strict_types=1);
namespace ChitChat\Room;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Mentions\MentionNotifier;
use ChitChat\Mentions\RoomMentionResolver;
use ChitChat\Realtime\EventRepository;
use ChitChat\Upload\AttachmentPolicy;
use PDO;
use RuntimeException;
use Throwable;

final class MessageService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly RoomMentionResolver $mentions;
    private readonly MentionNotifier $mentionNotifier;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->mentions = new RoomMentionResolver($pdo);
        $this->mentionNotifier = new MentionNotifier($pdo);
    }

    /**
     * @return list<array{
     *   id:int,
     *   room_id:int,
     *   sender_id:?int,
     *   username:?string,
     *   type:string,
     *   body:?string,
     *   attachment:?array{id:int, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool},
     *   deleted:bool,
     *   created_at:string,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     * }>
     */
    public function history(
        AuthenticatedUser $actor,
        int $roomId,
        ?int $beforeId = null,
        int $limit = 50,
    ): array {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $sql = <<<'SQL'
SELECT m.id,
       m.room_id,
       m.sender_id,
       u.username,
       m.message_type,
       m.body,
       m.created_at,
       (m.deleted_at IS NOT NULL)::int AS deleted,
       m.reply_to_message_kind,
       m.reply_to_message_id,
       rt.id AS reply_target_id,
       rt.sender_id AS reply_target_sender_id,
       rtu.username AS reply_target_username,
       rt.message_type AS reply_target_type,
       rt.body AS reply_target_body,
       (rt.deleted_at IS NOT NULL)::int AS reply_target_deleted,
       a.id AS attachment_id,
       a.original_name AS attachment_name,
       a.mime_type AS attachment_mime_type,
       a.size_bytes AS attachment_size_bytes,
       a.sha256 AS attachment_sha256,
       a.deleted_at AS attachment_deleted_at
FROM room_messages m
LEFT JOIN users u ON u.id = m.sender_id
LEFT JOIN room_messages rt ON rt.id = m.reply_to_message_id
LEFT JOIN users rtu ON rtu.id = rt.sender_id
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.room_id = :room_id
SQL;
        if ($beforeId !== null) {
            $sql .= "\n  AND m.id < :before_id";
        }
        $sql .= "\nORDER BY m.id DESC\nLIMIT :limit";

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare message history.');
        }
        $statement->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        if ($beforeId !== null) {
            $statement->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $messages = [];
        foreach (array_reverse($statement->fetchAll()) as $row) {
            if (is_array($row)) {
                $messages[] = $this->hydrateMessage($row);
            }
        }

        return $messages;
    }

    /**
     * @return array{
     *   id:int,
     *   room_id:int,
     *   sender_id:?int,
     *   username:?string,
     *   type:string,
     *   body:?string,
     *   attachment:?array{id:int, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool},
     *   deleted:bool,
     *   created_at:string,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     * }
     */
    public function send(
        AuthenticatedUser $actor,
        int $roomId,
        string $bodyInput,
        ?int $replyToMessageId = null,
    ): array {
        $room = $this->requireRoom($actor, $roomId);
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before sending messages.');
        }

        [$messageType, $body] = $this->parseMessage($bodyInput);
        if ($replyToMessageId !== null) {
            $this->requireReplyTargetInRoom($replyToMessageId, $roomId);
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_messages (room_id, sender_id, message_type, body, reply_to_message_kind, reply_to_message_id)
VALUES (:room_id, :sender_id, :message_type, :body, :reply_to_message_kind, :reply_to_message_id)
RETURNING id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare message send.');
            }
            $statement->execute([
                'room_id' => $roomId,
                'sender_id' => $actor->id,
                'message_type' => $messageType,
                'body' => $body,
                'reply_to_message_kind' => $replyToMessageId === null ? null : 'room',
                'reply_to_message_id' => $replyToMessageId,
            ]);
            $messageId = $statement->fetchColumn();
            if ($messageId === false) {
                throw new RuntimeException('Message send did not return an ID.');
            }
            $messageId = (int) $messageId;

            $mentions = $this->mentions->resolve($roomId, $actor->id, $body);
            $this->mentionNotifier->recordRoomMentions($messageId, $roomId, $mentions);

            $message = $this->storedMessage($messageId);
            $this->events->publish(
                type: 'room_message',
                payload: ['message' => $message],
                roomId: $roomId,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();

            return $message;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(
        AuthenticatedUser $actor,
        int $messageId,
        string $ipAddress,
    ): void {
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }

        $statement = $this->pdo->prepare('SELECT room_id FROM room_messages WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare message lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $roomIdValue = $statement->fetchColumn();
        if ($roomIdValue === false) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }
        $roomId = (int) $roomIdValue;
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireModerate($actor, $room);

        $this->pdo->beginTransaction();
        try {
            $deleteStatement = $this->pdo->prepare(<<<'SQL'
UPDATE room_messages
SET deleted_at = NOW(), deleted_by = :deleted_by
WHERE id = :id AND deleted_at IS NULL
SQL);
            if ($deleteStatement === false) {
                throw new RuntimeException('Unable to prepare message deletion.');
            }
            $deleteStatement->execute(['deleted_by' => $actor->id, 'id' => $messageId]);
            if ($deleteStatement->rowCount() === 0) {
                throw new ApiException(409, 'message_already_deleted', 'Message is already deleted.');
            }

            $attachmentStatement = $this->pdo->prepare(<<<'SQL'
UPDATE attachments
SET deleted_at = NOW(), deleted_by = :deleted_by
WHERE message_id = :message_id AND deleted_at IS NULL
SQL);
            if ($attachmentStatement === false) {
                throw new RuntimeException('Unable to prepare attachment deletion.');
            }
            $attachmentStatement->execute([
                'deleted_by' => $actor->id,
                'message_id' => $messageId,
            ]);

            $this->audit->log(
                $actor->id,
                'room.message_deleted',
                'room_message',
                (string) $messageId,
                ['room_id' => $roomId],
                $ipAddress,
            );
            $this->events->publish(
                type: 'message_deleted',
                payload: [
                    'message_id' => $messageId,
                    'room_id' => $roomId,
                    'deleted_by' => $actor->toArray(),
                ],
                roomId: $roomId,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   id:int,
     *   room_id:int,
     *   sender_id:?int,
     *   username:?string,
     *   type:string,
     *   body:?string,
     *   attachment:?array{id:int, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool},
     *   deleted:bool,
     *   created_at:string,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     * }
     */
    public function storedMessage(int $messageId): array
    {
        $message = $this->findStoredMessage($messageId);
        if ($message === null) {
            throw new RuntimeException('Stored message could not be reloaded.');
        }

        return $message;
    }

    /**
     * @return ?array{
     *   id:int,
     *   room_id:int,
     *   sender_id:?int,
     *   username:?string,
     *   type:string,
     *   body:?string,
     *   attachment:?array{id:int, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool},
     *   deleted:bool,
     *   created_at:string,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     * }
     */
    public function findStoredMessage(int $messageId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT m.id,
       m.room_id,
       m.sender_id,
       u.username,
       m.message_type,
       m.body,
       m.created_at,
       (m.deleted_at IS NOT NULL)::int AS deleted,
       m.reply_to_message_kind,
       m.reply_to_message_id,
       rt.id AS reply_target_id,
       rt.sender_id AS reply_target_sender_id,
       rtu.username AS reply_target_username,
       rt.message_type AS reply_target_type,
       rt.body AS reply_target_body,
       (rt.deleted_at IS NOT NULL)::int AS reply_target_deleted,
       a.id AS attachment_id,
       a.original_name AS attachment_name,
       a.mime_type AS attachment_mime_type,
       a.size_bytes AS attachment_size_bytes,
       a.sha256 AS attachment_sha256,
       a.deleted_at AS attachment_deleted_at
FROM room_messages m
LEFT JOIN users u ON u.id = m.sender_id
LEFT JOIN room_messages rt ON rt.id = m.reply_to_message_id
LEFT JOIN users rtu ON rtu.id = rt.sender_id
LEFT JOIN attachments a ON a.message_id = m.id
WHERE m.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare stored-message lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateMessage($row);
    }

    private function requireRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }

        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }

        return $room;
    }

    /** @return array{string, string} */
    private function parseMessage(string $bodyInput): array
    {
        $body = trim($bodyInput);
        if ($body === '') {
            throw new ApiException(400, 'empty_message', 'Message body cannot be empty.');
        }

        $messageType = 'text';
        if ($body === '/me' || str_starts_with($body, '/me ')) {
            $messageType = 'emote';
            $body = trim(substr($body, 3));
            if ($body === '') {
                throw new ApiException(400, 'empty_message', 'The /me command requires an action.');
            }
        } elseif (str_starts_with($body, '/')) {
            throw new ApiException(400, 'unknown_command', 'Unknown command. Supported commands are /me and /ping.');
        }

        if (mb_strlen($body, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Message body must not exceed 4000 characters.');
        }

        return [$messageType, $body];
    }

    private function requireReplyTargetInRoom(int $replyToMessageId, int $roomId): void
    {
        if ($replyToMessageId < 1) {
            throw new ApiException(400, 'validation_error', 'reply_to_message_id must be positive.');
        }

        $statement = $this->pdo->prepare('SELECT room_id FROM room_messages WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare reply-target lookup.');
        }
        $statement->execute(['id' => $replyToMessageId]);
        $targetRoomId = $statement->fetchColumn();
        if ($targetRoomId === false || (int) $targetRoomId !== $roomId) {
            throw new ApiException(400, 'invalid_reply_target', 'The message being replied to must be in this room.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id:int,
     *   room_id:int,
     *   sender_id:?int,
     *   username:?string,
     *   type:string,
     *   body:?string,
     *   attachment:?array{id:int, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool},
     *   deleted:bool,
     *   created_at:string,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     * }
     */
    private function hydrateMessage(array $row): array
    {
        $deleted = (int) $row['deleted'] === 1;
        $attachment = null;
        if (
            !$deleted
            && $row['attachment_id'] !== null
            && $row['attachment_deleted_at'] === null
        ) {
            $mimeType = (string) $row['attachment_mime_type'];
            $attachment = [
                'id' => (int) $row['attachment_id'],
                'name' => (string) $row['attachment_name'],
                'mime_type' => $mimeType,
                'size_bytes' => (int) $row['attachment_size_bytes'],
                'sha256' => (string) $row['attachment_sha256'],
                'previewable' => AttachmentPolicy::isPreviewable($mimeType),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'sender_id' => $row['sender_id'] === null ? null : (int) $row['sender_id'],
            'username' => $row['username'] === null ? null : (string) $row['username'],
            'type' => (string) $row['message_type'],
            'body' => $deleted ? null : (string) $row['body'],
            'attachment' => $attachment,
            'deleted' => $deleted,
            'created_at' => (string) $row['created_at'],
            'reply_to' => $this->hydrateReplyTo($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return ?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>}
     */
    private function hydrateReplyTo(array $row): ?array
    {
        if ($row['reply_to_message_id'] === null) {
            return null;
        }

        $targetExists = $row['reply_target_id'] !== null;
        $targetDeleted = $targetExists && (int) $row['reply_target_deleted'] === 1;

        return [
            'kind' => (string) $row['reply_to_message_kind'],
            'message_id' => (int) $row['reply_to_message_id'],
            'available' => $targetExists,
            'message' => !$targetExists ? null : [
                'id' => (int) $row['reply_target_id'],
                'sender' => $row['reply_target_sender_id'] === null ? null : [
                    'id' => (int) $row['reply_target_sender_id'],
                    'username' => $row['reply_target_username'] === null ? null : (string) $row['reply_target_username'],
                ],
                'type' => (string) $row['reply_target_type'],
                'body' => $targetDeleted ? null : (string) $row['reply_target_body'],
                'deleted' => $targetDeleted,
            ],
        ];
    }
}
