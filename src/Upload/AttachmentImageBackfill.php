<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** Explicit bounded operator work; never called by downloads or automatic maintenance. */
final class AttachmentImageBackfill
{
    private readonly AttachmentFileStore $files;

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        $this->files = new AttachmentFileStore($config);
    }

    /** @return array{kind:string,dry_run:bool,candidates:int,updated:int,previews_generated:int,unavailable:int,next_id:int} */
    public function run(string $kind, int $limit = 25, int $afterId = 0, bool $dryRun = true): array
    {
        if (!in_array($kind, ['room', 'direct'], true) || $limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Use kind room/direct, limit 1-100, and a non-negative after-id.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Image backfill must own its transactions.');
        }
        // Same session-level lock as CleanupService: prevents a rendition being added
        // between retention's key collection and physical file removal.
        $locked = $this->pdo->query("SELECT pg_try_advisory_lock(hashtext('chitchat-maintenance-cleanup'))::int");
        if ($locked === false || (int) $locked->fetchColumn() !== 1) {
            throw new RuntimeException('Maintenance cleanup or image backfill is already running.');
        }
        try {
            $table = $kind === 'room' ? 'attachments' : 'direct_message_attachments';
            $from = $kind === 'room'
                ? 'FROM attachments a JOIN room_messages m ON m.id = a.message_id JOIN rooms r ON r.id = a.room_id'
                : 'FROM direct_message_attachments a JOIN direct_messages m ON m.id = a.direct_message_id';
            $eligible = 'a.deleted_at IS NULL AND m.deleted_at IS NULL'
                . ($kind === 'room' ? ' AND r.deleted_at IS NULL' : '')
                . " AND a.mime_type IN ('image/jpeg','image/png','image/gif','image/webp')"
                . ' AND (a.preview_storage_key IS NULL OR a.image_width IS NULL OR a.image_height IS NULL)';
            $statement = $this->prepare('SELECT a.id ' . $from . ' WHERE ' . $eligible
                . ' AND a.id > :after ORDER BY a.id LIMIT :limit');
            $statement->bindValue(':after', $afterId, PDO::PARAM_INT);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
            $result = ['kind' => $kind, 'dry_run' => $dryRun, 'candidates' => count($ids),
                'updated' => 0, 'previews_generated' => 0, 'unavailable' => 0, 'next_id' => $afterId];
            foreach ($ids as $id) {
                $id = (int) $id;
                $result['next_id'] = $id;
                if ($dryRun) {
                    continue;
                }
                $metadata = AttachmentImageMetadata::emptyStored();
                $this->pdo->beginTransaction();
                try {
                    $rowStatement = $this->prepare('SELECT a.storage_key, a.size_bytes, a.mime_type '
                        . $from . ' WHERE a.id = :id AND ' . $eligible . ' FOR UPDATE OF a');
                    $rowStatement->execute(['id' => $id]);
                    $row = $rowStatement->fetch();
                    if (!is_array($row)) {
                        $this->pdo->commit();
                        continue; // A concurrent delete or completed upload made it ineligible.
                    }
                    try {
                        $path = $this->files->resolve((string) $row['storage_key'], (int) $row['size_bytes']);
                    } catch (ApiException $exception) {
                        if ($exception->status !== 410) {
                            throw $exception;
                        }
                        $result['unavailable']++;
                        $this->pdo->commit();
                        continue;
                    }
                    $metadata = $this->files->imageMetadata($path, (string) $row['mime_type']);
                    AttachmentImageMetadata::save($this->pdo, $table, $id, $metadata);
                    $this->pdo->commit();
                    $result['updated']++;
                    if ($metadata['preview_storage_key'] === null) {
                        $result['unavailable']++;
                    } else {
                        $result['previews_generated']++;
                    }
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    if (is_string($metadata['preview_storage_key'])) {
                        $this->files->remove($metadata['preview_storage_key']);
                    }
                    throw $exception;
                }
            }
            if (!$dryRun) {
                (new AuditLogger($this->pdo))->log(null, 'maintenance.attachment_images_backfilled',
                    'system', $kind, $result, '127.0.0.1');
            }
            return $result;
        } finally {
            $this->pdo->query("SELECT pg_advisory_unlock(hashtext('chitchat-maintenance-cleanup'))");
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare attachment image backfill.');
        }
        return $statement;
    }
}
