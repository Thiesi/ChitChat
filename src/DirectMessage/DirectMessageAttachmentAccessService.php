<?php

declare(strict_types=1);

namespace ChitChat\DirectMessage;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
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
     *   previewable:bool, width:?int, height:?int, preview:?array{url:string,width:int,height:int,mime_type:string,size_bytes:int,sha256:string}
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

    /** @return array{path:string, name:string, mime_type:string, size_bytes:int, sha256:string, previewable:bool, width:?int, height:?int, preview:?array{url:string,width:int,height:int,mime_type:string,size_bytes:int,sha256:string}} */
    public function authorizeDownload(AuthenticatedUser $actor, int $attachmentId, string $variant = 'original'): array
    {
        // No deleted check here (#356): DirectMessageAttachmentService checks the participant
        // first, so someone outside the conversation gets the same 404 for a deleted attachment
        // as for one that never existed, and only a participant learns it was deleted (410).
        return $this->attachments->authorizeDownload($actor, $attachmentId, $variant);
    }
}
