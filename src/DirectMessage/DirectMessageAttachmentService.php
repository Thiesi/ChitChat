<?php

declare(strict_types=1);
namespace ChitChat\DirectMessage;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
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

    public function __construct(
        private readonly PDO $pdo,
        Config $config,
    ) {
        $this->users = new UserRepository($pdo);
        $this->blocks = new DirectMessageBlockService($pdo);
        $this->events = new EventRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->files = new AttachmentFileStore($config);
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
    public function upload(
        AuthenticatedUser $actor,
        int $recipientUserId,
        IncomingFile $file,
        string $captionInput,
        string $ipAddress,
    ): array {
        $this->requireOtherUser($actor, $recipientUserId);
        $caption = trim($captionInput);
        if (mb_strlen($caption, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Attachment caption must not exceed 4000 characters.');
        }

        $stored = $this->files->store($file);
        $body = $caption === '' ? $stored['name'] : $caption;

        try {
            $this->pdo->beginTransaction();
            $this->blocks->lockPair($actor->id, $recipientUserId);
            $this->blocks->requireMessagingAvailable($actor, $recipientUserId);

            $messageStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body)
VALUES (:sender_user_id, :recipient_user_id, :body)
RETURNING id
SQL);
            if ($messageStatement === false) {
                throw new RuntimeException('Unable to prepare direct-message attachment creation.');
            }
            $messageStatement->execute([
                'sender_user_id' => $actor->id,
                'recipient_user_id' => $recipientUserId,
                'body' => $body,
            ]);
            $messageIdValue = $messageStatement->fetchColumn();
            if ($messageIdValue === false) {
                throw new RuntimeException('Direct-message attachment creation did not return an ID.');
            }
            $messageId = (int) $messageIdValue;

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

    private function requireOtherUser(AuthenticatedUser $actor, int $otherUserId): void
    {
        if ($otherUserId < 1) {
            throw new ApiException(400, 'validation_error', 'recipient_user_id must be positive.');
        }
        if ($otherUserId === $actor->id) {
            throw new ApiException(400, 'direct_message_self_forbidden', 'You cannot send direct messages to yourself.');
        }
        if ($this->users->findAuthenticatedById($otherUserId) === null) {
            throw new ApiException(404, 'user_not_found', 'User not found.');
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
        ];
    }
}
