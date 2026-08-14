<?php

declare(strict_types=1);
namespace ChitChat\DirectMessage;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Mentions\DirectMessageMentionResolver;
use ChitChat\Mentions\MentionNotifier;
use ChitChat\Realtime\EventRepository;
use ChitChat\Upload\AttachmentFileStore;
use ChitChat\Upload\AttachmentPolicy;
use ChitChat\Upload\IncomingFile;
use PDO;
use RuntimeException;
use Throwable;

final class DirectMessageAttachmentService
{
    private readonly UserRepository $users;
    private readonly DirectMessageBlockService $blocks;
    private readonly EventRepository $events;
    private readonly AuditLogger $audit;
    private readonly AttachmentFileStore $files;
    private readonly MentionNotifier $mentionNotifier;

    public function __construct(
        private readonly PDO $pdo,
        Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->blocks = new DirectMessageBlockService($pdo);
        $this->events = new EventRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->files = new AttachmentFileStore($config);
        $this->mentionNotifier = new MentionNotifier($pdo);
    }

    /**
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>},
     *   mentions:list<array{user_id:int, username:string}>
     * }
     */
    public function upload(
        AuthenticatedUser $actor,
        int $recipientUserId,
        IncomingFile $file,
        string $captionInput,
        string $ipAddress,
        ?int $replyToMessageId = null,
    ): array {
        $recipient = $this->requireOtherUser($actor, $recipientUserId);
        $caption = trim($captionInput);
        if (mb_strlen($caption, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Attachment caption must not exceed 4000 characters.');
        }
        if ($replyToMessageId !== null) {
            $this->requireReplyTargetInConversation($replyToMessageId, $actor->id, $recipientUserId);
        }

        $stored = $this->files->store($file);
        $body = $caption === '' ? $stored['name'] : $caption;

        try {
            $this->pdo->beginTransaction();
            $this->blocks->lockPair($actor->id, $recipientUserId);
            $this->blocks->requireMessagingAvailable($actor, $recipientUserId);

            $messageStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body, reply_to_message_kind, reply_to_message_id)
VALUES (:sender_user_id, :recipient_user_id, :body, :reply_to_message_kind, :reply_to_message_id)
RETURNING id
SQL);
            if ($messageStatement === false) {
                throw new RuntimeException('Unable to prepare direct-message attachment creation.');
            }
            $messageStatement->execute([
                'sender_user_id' => $actor->id,
                'recipient_user_id' => $recipientUserId,
                'body' => $body,
                'reply_to_message_kind' => $replyToMessageId === null ? null : 'direct',
                'reply_to_message_id' => $replyToMessageId,
            ]);
            $messageIdValue = $messageStatement->fetchColumn();
            if ($messageIdValue === false) {
                throw new RuntimeException('Direct-message attachment creation did not return an ID.');
            }
            $messageId = (int) $messageIdValue;

            if ($caption !== '') {
                $mentions = DirectMessageMentionResolver::resolve($recipientUserId, $recipient->username, $caption);
                $this->mentionNotifier->recordDirectMessageMentions($messageId, $actor->id, $actor->username, $mentions);
            }

            $attachmentStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_message_attachments (
    direct_message_id,
    uploader_user_id,
    storage_key,
    original_name,
    mime_type,
    size_bytes,
    sha256
)
VALUES (
    :direct_message_id,
    :uploader_user_id,
    :storage_key,
    :original_name,
    :mime_type,
    :size_bytes,
    :sha256
)
RETURNING id
SQL);
            if ($attachmentStatement === false) {
                throw new RuntimeException('Unable to prepare direct-message attachment metadata.');
            }
            $attachmentStatement->execute([
                'direct_message_id' => $messageId,
                'uploader_user_id' => $actor->id,
                'storage_key' => $stored['storage_key'],
                'original_name' => $stored['name'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'sha256' => $stored['sha256'],
            ]);
            $attachmentIdValue = $attachmentStatement->fetchColumn();
            if ($attachmentIdValue === false) {
                throw new RuntimeException('Direct-message attachment metadata did not return an ID.');
            }
            $attachmentId = (int) $attachmentIdValue;

            $senderMessage = $this->messageById($messageId, $actor->id);
            $recipientMessage = $this->messageById($messageId, $recipientUserId);
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'direct_message.attachment_uploaded',
                subjectType: 'direct_message_attachment',
                subjectId: (string) $attachmentId,
                metadata: [
                    'direct_message_id' => $messageId,
                    'recipient_user_id' => $recipientUserId,
                    'original_name' => $stored['name'],
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                    'sha256' => $stored['sha256'],
                ],
                ipAddress: $ipAddress,
            );
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
            $this->files->remove($stored['storage_key']);
            throw $exception;
        }
    }

    /**
     * @param list<int> $messageIds
     * @return list<array{
     *   id:int,
     *   message_id:int,
     *   name:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   sha256:string,
     *   previewable:bool
     * }>
     */
    public function metadata(AuthenticatedUser $actor, array $messageIds): array
    {
        if ($messageIds === [] || count($messageIds) > 100) {
            throw new ApiException(400, 'validation_error', 'message_ids must contain 1-100 message IDs.');
        }
        foreach ($messageIds as $messageId) {
            if ($messageId < 1) {
                throw new ApiException(400, 'validation_error', 'message_ids must contain positive integers.');
            }
        }

        $placeholders = [];
        foreach ($messageIds as $index => $_messageId) {
            $placeholders[] = ':message_' . $index;
        }
        $sql = <<<SQL
SELECT dma.id,
       dma.direct_message_id,
       dma.original_name,
       dma.mime_type,
       dma.size_bytes,
       dma.sha256
FROM direct_message_attachments dma
JOIN direct_messages dm ON dm.id = dma.direct_message_id
WHERE dma.direct_message_id IN (%s)
  AND (dm.sender_user_id = :actor_sender OR dm.recipient_user_id = :actor_recipient)
ORDER BY dma.direct_message_id
SQL;
        $statement = $this->pdo->prepare(sprintf($sql, implode(', ', $placeholders)));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message attachment metadata lookup.');
        }
        foreach ($messageIds as $index => $messageId) {
            $statement->bindValue(':message_' . $index, $messageId, PDO::PARAM_INT);
        }
        $statement->bindValue(':actor_sender', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':actor_recipient', $actor->id, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mimeType = (string) $row['mime_type'];
            $result[] = [
                'id' => (int) $row['id'],
                'message_id' => (int) $row['direct_message_id'],
                'name' => (string) $row['original_name'],
                'mime_type' => $mimeType,
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => (string) $row['sha256'],
                'previewable' => AttachmentPolicy::isPreviewable($mimeType),
            ];
        }

        return $result;
    }

    /**
     * @return array{path:string, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool}
     */
    public function authorizeDownload(AuthenticatedUser $actor, int $attachmentId): array
    {
        if ($attachmentId < 1) {
            throw new ApiException(400, 'validation_error', 'id must be positive.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT dma.storage_key,
       dma.original_name,
       dma.mime_type,
       dma.size_bytes,
       dma.sha256,
       dm.sender_user_id,
       dm.recipient_user_id
FROM direct_message_attachments dma
JOIN direct_messages dm ON dm.id = dma.direct_message_id
WHERE dma.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message attachment download lookup.');
        }
        $statement->execute(['id' => $attachmentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'attachment_not_found', 'Attachment not found.');
        }
        if ((int) $row['sender_user_id'] !== $actor->id && (int) $row['recipient_user_id'] !== $actor->id) {
            throw new ApiException(404, 'attachment_not_found', 'Attachment not found.');
        }

        $mimeType = (string) $row['mime_type'];
        return [
            'path' => $this->files->resolve((string) $row['storage_key'], (int) $row['size_bytes']),
            'name' => (string) $row['original_name'],
            'mime_type' => $mimeType,
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => (string) $row['sha256'],
            'previewable' => AttachmentPolicy::isPreviewable($mimeType),
        ];
    }

    private function requireOtherUser(AuthenticatedUser $actor, int $otherUserId): AuthenticatedUser
    {
        if ($otherUserId < 1) {
            throw new ApiException(400, 'validation_error', 'recipient_user_id must be positive.');
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

    private function requireReplyTargetInConversation(int $replyToMessageId, int $actorId, int $otherUserId): void
    {
        if ($replyToMessageId < 1) {
            throw new ApiException(400, 'validation_error', 'reply_to_message_id must be positive.');
        }

        $statement = $this->pdo->prepare(
            'SELECT sender_user_id, recipient_user_id FROM direct_messages WHERE id = :id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare reply-target lookup.');
        }
        $statement->execute(['id' => $replyToMessageId]);
        $row = $statement->fetch();
        $participants = is_array($row) ? [(int) $row['sender_user_id'], (int) $row['recipient_user_id']] : [];
        sort($participants);
        $expected = [$actorId, $otherUserId];
        sort($expected);
        if ($participants !== $expected) {
            throw new ApiException(
                400,
                'invalid_reply_target',
                'The message being replied to must be in this conversation.',
            );
        }
    }

    /**
     * @return array{
     *   id:int,
     *   sender:array{id:int, username:string},
     *   recipient:array{id:int, username:string},
     *   body:string,
     *   read_at:?string,
     *   created_at:string,
     *   outgoing:bool,
     *   reply_to:?array{kind:string, message_id:int, available:bool, message:?array<string, mixed>},
     *   mentions:list<array{user_id:int, username:string}>
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
       dm.created_at,
       dm.reply_to_message_kind,
       dm.reply_to_message_id,
       rt.id AS reply_target_id,
       rt.sender_user_id AS reply_target_sender_id,
       rtu.username AS reply_target_sender_username,
       rt.body AS reply_target_body,
       (rt.deleted_at IS NOT NULL)::int AS reply_target_deleted,
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
       ) AS mentions_json
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users recipient ON recipient.id = dm.recipient_user_id
LEFT JOIN direct_messages rt ON rt.id = dm.reply_to_message_id
LEFT JOIN users rtu ON rtu.id = rt.sender_user_id
WHERE dm.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message attachment message lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Direct-message attachment could not be reloaded.');
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
            'body' => (string) $row['body'],
            'read_at' => $row['recipient_read_at'] === null ? null : (string) $row['recipient_read_at'],
            'created_at' => (string) $row['created_at'],
            'outgoing' => (int) $row['sender_user_id'] === $viewerUserId,
            'reply_to' => $this->hydrateReplyTo($row),
            'mentions' => $this->hydrateMentions($row),
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

        return [
            'kind' => (string) $row['reply_to_message_kind'],
            'message_id' => (int) $row['reply_to_message_id'],
            'available' => $targetExists,
            'message' => !$targetExists ? null : [
                'id' => (int) $row['reply_target_id'],
                'sender' => [
                    'id' => (int) $row['reply_target_sender_id'],
                    'username' => (string) $row['reply_target_sender_username'],
                ],
                'body' => (string) $row['reply_target_body'],
                'deleted' => (int) $row['reply_target_deleted'] === 1,
            ],
        ];
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
}
