<?php

declare(strict_types=1);
namespace ChitChat\Upload;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use finfo;
use PDO;
use RuntimeException;
use Throwable;

final class AttachmentService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
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
     *   created_at:string
     * }
     */
    public function upload(
        AuthenticatedUser $actor,
        int $roomId,
        IncomingFile $file,
        string $captionInput,
        string $ipAddress,
    ): array {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }

        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before uploading attachments.');
        }
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        $originalName = $this->normalizeOriginalName($file->originalName);
        $caption = trim($captionInput);
        if (mb_strlen($caption, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Attachment caption must not exceed 4000 characters.');
        }
        $body = $caption === '' ? $originalName : $caption;

        if ($file->reportedSize < 1) {
            throw new ApiException(400, 'attachment_empty', 'Empty files cannot be uploaded.');
        }
        if ($file->reportedSize > $this->config->attachmentMaxBytes) {
            throw new ApiException(413, 'attachment_too_large', 'The attachment exceeds the configured size limit.');
        }

        $mimeType = $this->detectMimeType($file->temporaryPath);
        if (!AttachmentPolicy::isAllowed($mimeType)) {
            throw new ApiException(
                415,
                'attachment_type_not_allowed',
                'That file type is not allowed for attachments.',
            );
        }

        $storageKey = bin2hex(random_bytes(32));
        $storagePath = $this->storagePath($storageKey);
        $this->prepareStorageDirectory($storagePath);

        $fileMoved = false;
        try {
            $file->moveTo($storagePath);
            $fileMoved = true;

            $actualSize = filesize($storagePath);
            if ($actualSize === false || $actualSize < 1) {
                throw new RuntimeException('Unable to determine stored attachment size.');
            }
            if ($actualSize > $this->config->attachmentMaxBytes) {
                throw new ApiException(413, 'attachment_too_large', 'The attachment exceeds the configured size limit.');
            }

            $sha256 = hash_file('sha256', $storagePath);
            if ($sha256 === false) {
                throw new RuntimeException('Unable to hash the stored attachment.');
            }

            $this->pdo->beginTransaction();
            $messageStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_messages (room_id, sender_id, message_type, body)
VALUES (:room_id, :sender_id, 'attachment', :body)
RETURNING id
SQL);
            if ($messageStatement === false) {
                throw new RuntimeException('Unable to prepare attachment message creation.');
            }
            $messageStatement->execute([
                'room_id' => $roomId,
                'sender_id' => $actor->id,
                'body' => $body,
            ]);
            $messageIdValue = $messageStatement->fetchColumn();
            if ($messageIdValue === false) {
                throw new RuntimeException('Attachment message creation did not return an ID.');
            }
            $messageId = (int) $messageIdValue;

            $attachmentStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO attachments (
    message_id,
    room_id,
    uploader_user_id,
    storage_key,
    original_name,
    mime_type,
    size_bytes,
    sha256
)
VALUES (
    :message_id,
    :room_id,
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
                throw new RuntimeException('Unable to prepare attachment metadata creation.');
            }
            $attachmentStatement->execute([
                'message_id' => $messageId,
                'room_id' => $roomId,
                'uploader_user_id' => $actor->id,
                'storage_key' => $storageKey,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $actualSize,
                'sha256' => $sha256,
            ]);
            $attachmentIdValue = $attachmentStatement->fetchColumn();
            if ($attachmentIdValue === false) {
                throw new RuntimeException('Attachment metadata creation did not return an ID.');
            }
            $attachmentId = (int) $attachmentIdValue;

            $message = (new MessageService($this->pdo))->storedMessage($messageId);
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'room.attachment_uploaded',
                subjectType: 'attachment',
                subjectId: (string) $attachmentId,
                metadata: [
                    'room_id' => $roomId,
                    'message_id' => $messageId,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => $actualSize,
                    'sha256' => $sha256,
                ],
                ipAddress: $ipAddress,
            );
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
            if ($fileMoved && is_file($storagePath)) {
                @unlink($storagePath);
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   path:string,
     *   name:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   sha256:string,
     *   previewable:bool
     * }
     */
    public function authorizeDownload(AuthenticatedUser $actor, int $attachmentId): array
    {
        if ($attachmentId < 1) {
            throw new ApiException(400, 'validation_error', 'id must be positive.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT a.room_id,
       a.storage_key,
       a.original_name,
       a.mime_type,
       a.size_bytes,
       a.sha256,
       a.deleted_at,
       m.deleted_at AS message_deleted_at
FROM attachments a
JOIN room_messages m ON m.id = a.message_id
WHERE a.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare attachment download lookup.');
        }
        $statement->execute(['id' => $attachmentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'attachment_not_found', 'Attachment not found.');
        }
        if ($row['deleted_at'] !== null || $row['message_deleted_at'] !== null) {
            throw new ApiException(410, 'attachment_deleted', 'This attachment is no longer available.');
        }

        $roomId = (int) $row['room_id'];
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'attachment_not_found', 'Attachment not found.');
        }
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        $storageKey = (string) $row['storage_key'];
        $path = $this->storagePath($storageKey);
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException(410, 'attachment_storage_missing', 'The attachment file is unavailable.');
        }
        $actualSize = filesize($path);
        if ($actualSize === false || $actualSize !== (int) $row['size_bytes']) {
            throw new ApiException(410, 'attachment_storage_invalid', 'The attachment file failed an integrity check.');
        }

        $mimeType = (string) $row['mime_type'];
        return [
            'path' => $path,
            'name' => (string) $row['original_name'],
            'mime_type' => $mimeType,
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => (string) $row['sha256'],
            'previewable' => AttachmentPolicy::isPreviewable($mimeType),
        ];
    }

    private function detectMimeType(string $path): string
    {
        $detector = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $detector->file($path);
        if (!is_string($mimeType) || $mimeType === '') {
            throw new ApiException(415, 'attachment_type_unknown', 'The file type could not be determined.');
        }

        return strtolower($mimeType);
    }

    private function normalizeOriginalName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = 'attachment';
        }
        $name = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(is_string($clean) ? $clean : '');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }
        if (mb_strlen($name, 'UTF-8') > 255) {
            $name = mb_substr($name, 0, 255, 'UTF-8');
        }

        return $name;
    }

    private function storagePath(string $storageKey): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $storageKey) !== 1) {
            throw new RuntimeException('Stored attachment key is invalid.');
        }
        $base = rtrim($this->config->attachmentStoragePath, '/\\');

        return $base
            . DIRECTORY_SEPARATOR . substr($storageKey, 0, 2)
            . DIRECTORY_SEPARATOR . substr($storageKey, 2, 2)
            . DIRECTORY_SEPARATOR . $storageKey;
    }

    private function prepareStorageDirectory(string $storagePath): void
    {
        $base = rtrim($this->config->attachmentStoragePath, '/\\');
        $directories = [
            $base,
            dirname(dirname($storagePath)),
            dirname($storagePath),
        ];

        foreach ($directories as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('Attachment storage directories must not be symbolic links.');
            }
            if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create attachment storage directory.');
            }
            if (!is_writable($directory)) {
                throw new RuntimeException('Attachment storage directory is not writable.');
            }
        }
    }
}
