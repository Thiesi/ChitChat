<?php

declare(strict_types=1);

namespace ChitChat\DirectMessage;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

final class DirectMessageAttachmentAccessService
{
    private readonly DirectMessageAttachmentService $attachments;

    public function __construct(
        private readonly PDO $pdo,
        Config $config,
    ) {
        $this->attachments = new DirectMessageAttachmentService($pdo, $config);
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
        $metadata = $this->attachments->metadata($actor, $messageIds);
        if ($metadata === []) {
            return [];
        }

        $ids = array_map(
            static fn (array $attachment): int => $attachment['id'],
            $metadata,
        );
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $name = 'attachment_id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM direct_message_attachments WHERE deleted_at IS NOT NULL AND id IN ('
            . implode(', ', $placeholders)
            . ')',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare deleted direct-message attachment lookup.');
        }
        $statement->execute($parameters);
        $deleted = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $deleted[(int) $id] = true;
        }

        return array_values(array_filter(
            $metadata,
            static fn (array $attachment): bool => !isset($deleted[$attachment['id']]),
        ));
    }

    /** @return array{path:string, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool} */
    public function authorizeDownload(AuthenticatedUser $actor, int $attachmentId): array
    {
        if ($attachmentId < 1) {
            throw new ApiException(400, 'validation_error', 'id must be positive.');
        }
        $statement = $this->pdo->prepare(
            'SELECT deleted_at FROM direct_message_attachments WHERE id = :id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message attachment deletion lookup.');
        }
        $statement->execute(['id' => $attachmentId]);
        $deletedAt = $statement->fetchColumn();
        if ($deletedAt === false) {
            throw new ApiException(404, 'attachment_not_found', 'Attachment not found.');
        }
        if ($deletedAt !== null) {
            throw new ApiException(410, 'attachment_deleted', 'This attachment is no longer available.');
        }

        return $this->attachments->authorizeDownload($actor, $attachmentId);
    }
}
