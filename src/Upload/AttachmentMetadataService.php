<?php

declare(strict_types=1);
namespace ChitChat\Upload;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use PDO;
use RuntimeException;

final class AttachmentMetadataService
{
    private readonly RoomRepository $rooms;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
    }

    /**
     * @param list<int> $messageIds
     * @return list<array{
     *   message_id:int,
     *   id:int,
     *   name:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   sha256:string,
     *   previewable:bool
     * }>
     */
    public function forMessages(AuthenticatedUser $actor, int $roomId, array $messageIds): array
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $messageIds = array_values(array_unique($messageIds));
        if ($messageIds === [] || count($messageIds) > 100) {
            throw new ApiException(400, 'validation_error', 'message_ids must contain between 1 and 100 IDs.');
        }
        foreach ($messageIds as $messageId) {
            if ($messageId < 1) {
                throw new ApiException(400, 'validation_error', 'message_ids must contain positive integers.');
            }
        }

        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        $placeholders = [];
        foreach ($messageIds as $index => $messageId) {
            $placeholders[] = ':message_' . $index;
        }
        $sql = <<<'SQL'
SELECT a.message_id,
       a.id,
       a.original_name,
       a.mime_type,
       a.size_bytes,
       a.sha256
FROM attachments a
JOIN room_messages m ON m.id = a.message_id
WHERE a.room_id = :room_id
  AND a.deleted_at IS NULL
  AND m.deleted_at IS NULL
  AND a.message_id IN (%s)
ORDER BY a.message_id
SQL;
        $statement = $this->pdo->prepare(sprintf($sql, implode(', ', $placeholders)));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare attachment metadata query.');
        }
        $statement->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        foreach ($messageIds as $index => $messageId) {
            $statement->bindValue(':message_' . $index, $messageId, PDO::PARAM_INT);
        }
        $statement->execute();

        $attachments = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mimeType = (string) $row['mime_type'];
            $attachments[] = [
                'message_id' => (int) $row['message_id'],
                'id' => (int) $row['id'],
                'name' => (string) $row['original_name'],
                'mime_type' => $mimeType,
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => (string) $row['sha256'],
                'previewable' => AttachmentPolicy::isPreviewable($mimeType),
            ];
        }

        return $attachments;
    }
}
