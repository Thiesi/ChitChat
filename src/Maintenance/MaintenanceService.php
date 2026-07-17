<?php

declare(strict_types=1);

namespace ChitChat\Maintenance;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use DateTimeImmutable;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class MaintenanceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /** @return array<string, int|bool> */
    public function run(bool $dryRun): array
    {
        if (!$this->acquireLock()) {
            throw new RuntimeException('Another maintenance cleanup is already running.');
        }

        try {
            $settings = $this->settings();
            $roomCutoff = $this->cutoffDays($settings['room_message_retention_days']);
            $directCutoff = $this->cutoffDays($settings['direct_message_retention_days']);
            $auditCutoff = $this->cutoffDays($settings['audit_retention_days']);
            $attachmentCutoff = $this->cutoffDays($settings['deleted_attachment_retention_days']);
            $eventCutoff = $this->cutoffHours($settings['realtime_event_retention_hours']);
            $loginCutoff = $this->cutoffDays($settings['login_attempt_retention_days']);
            $orphanCutoff = $this->cutoffHours($settings['orphan_attachment_grace_hours']);

            $roomAttachmentKeys = $roomCutoff === null
                ? []
                : $this->storageKeysForRoomMessages($roomCutoff);
            $deletedAttachmentKeys = $attachmentCutoff === null
                ? []
                : $this->storageKeysForDeletedAttachments($attachmentCutoff);
            $orphanPaths = $this->orphanPaths($orphanCutoff);

            $result = [
                'dry_run' => $dryRun,
                'direct_messages' => $directCutoff === null
                    ? 0
                    : $this->countBefore('direct_messages', 'created_at', $directCutoff),
                'room_messages' => $roomCutoff === null
                    ? 0
                    : $this->countBefore('room_messages', 'created_at', $roomCutoff),
                'deleted_attachments' => count($deletedAttachmentKeys),
                'audit_entries' => $auditCutoff === null
                    ? 0
                    : $this->countBefore('audit_log', 'created_at', $auditCutoff),
                'realtime_events' => $this->countRealtimeEvents($eventCutoff),
                'login_attempts' => $this->countBefore('login_attempts', 'created_at', $loginCutoff),
                'rate_limit_rows' => $this->countBefore(
                    'request_rate_limits',
                    'updated_at',
                    new DateTimeImmutable('-2 days'),
                ),
                'expired_presence_leases' => $this->countExpiredPresence(),
                'tracked_files' => count(array_unique(array_merge($roomAttachmentKeys, $deletedAttachmentKeys))),
                'orphan_files' => count($orphanPaths),
                'files_removed' => 0,
                'file_removal_failures' => 0,
            ];

            if ($dryRun) {
                return $result;
            }

            $this->pdo->beginTransaction();
            try {
                $result['direct_messages'] = $directCutoff === null
                    ? 0
                    : $this->deleteBefore('direct_messages', 'created_at', $directCutoff);
                $result['room_messages'] = $roomCutoff === null
                    ? 0
                    : $this->deleteBefore('room_messages', 'created_at', $roomCutoff);
                $result['deleted_attachments'] = $attachmentCutoff === null
                    ? 0
                    : $this->deleteBefore('attachments', 'deleted_at', $attachmentCutoff, true);
                $result['audit_entries'] = $auditCutoff === null
                    ? 0
                    : $this->deleteBefore('audit_log', 'created_at', $auditCutoff);
                $result['realtime_events'] = $this->deleteRealtimeEvents($eventCutoff);
                $result['login_attempts'] = $this->deleteBefore('login_attempts', 'created_at', $loginCutoff);
                $result['rate_limit_rows'] = $this->deleteBefore(
                    'request_rate_limits',
                    'updated_at',
                    new DateTimeImmutable('-2 days'),
                );
                $result['expired_presence_leases'] = $this->deleteExpiredPresence();
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            $trackedKeys = array_unique(array_merge($roomAttachmentKeys, $deletedAttachmentKeys));
            foreach ($trackedKeys as $storageKey) {
                $this->removeFile($this->storagePath($storageKey), $result);
            }
            foreach ($orphanPaths as $path) {
                $this->removeFile($path, $result);
            }
            $this->removeEmptyShardDirectories();

            (new AuditLogger($this->pdo))->log(
                actorUserId: null,
                action: 'maintenance.cleanup',
                subjectType: 'system',
                subjectId: null,
                metadata: $result,
                ipAddress: '127.0.0.1',
            );

            return $result;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        $statement = $this->pdo->query(
            "SELECT pg_try_advisory_lock(hashtext('chitchat-maintenance-cleanup'))::int",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to acquire maintenance lock.');
        }

        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        $this->pdo->query("SELECT pg_advisory_unlock(hashtext('chitchat-maintenance-cleanup'))");
    }

    /**
     * @return array{
     *   room_message_retention_days:int,
     *   direct_message_retention_days:int,
     *   audit_retention_days:int,
     *   deleted_attachment_retention_days:int,
     *   orphan_attachment_grace_hours:int,
     *   realtime_event_retention_hours:int,
     *   login_attempt_retention_days:int
     * }
     */
    private function settings(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT room_message_retention_days,
       direct_message_retention_days,
       audit_retention_days,
       deleted_attachment_retention_days,
       orphan_attachment_grace_hours,
       realtime_event_retention_hours,
       login_attempt_retention_days
FROM system_settings
WHERE id = 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to load maintenance settings.');
        }
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Maintenance settings are missing.');
        }

        return [
            'room_message_retention_days' => (int) $row['room_message_retention_days'],
            'direct_message_retention_days' => (int) $row['direct_message_retention_days'],
            'audit_retention_days' => (int) $row['audit_retention_days'],
            'deleted_attachment_retention_days' => (int) $row['deleted_attachment_retention_days'],
            'orphan_attachment_grace_hours' => (int) $row['orphan_attachment_grace_hours'],
            'realtime_event_retention_hours' => (int) $row['realtime_event_retention_hours'],
            'login_attempt_retention_days' => (int) $row['login_attempt_retention_days'],
        ];
    }

    private function cutoffDays(int $days): ?DateTimeImmutable
    {
        return $days === 0 ? null : new DateTimeImmutable(sprintf('-%d days', $days));
    }

    private function cutoffHours(int $hours): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('-%d hours', $hours));
    }

    private function countBefore(string $table, string $column, DateTimeImmutable $cutoff): int
    {
        $this->requireKnownTableColumn($table, $column);
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s < :cutoff',
            $table,
            $column,
            $column,
        ));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance count.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return (int) $statement->fetchColumn();
    }

    private function deleteBefore(
        string $table,
        string $column,
        DateTimeImmutable $cutoff,
        bool $requireNonNull = false,
    ): int {
        $this->requireKnownTableColumn($table, $column);
        $nonNull = $requireNonNull ? sprintf('%s IS NOT NULL AND ', $column) : '';
        $statement = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE %s%s < :cutoff',
            $table,
            $nonNull,
            $column,
        ));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance deletion.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return $statement->rowCount();
    }

    private function requireKnownTableColumn(string $table, string $column): void
    {
        $known = [
            'direct_messages.created_at',
            'room_messages.created_at',
            'attachments.deleted_at',
            'audit_log.created_at',
            'login_attempts.created_at',
            'request_rate_limits.updated_at',
        ];
        if (!in_array($table . '.' . $column, $known, true)) {
            throw new RuntimeException('Unsupported maintenance table or column.');
        }
    }

    /** @return list<string> */
    private function storageKeysForRoomMessages(DateTimeImmutable $cutoff): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT a.storage_key
FROM attachments a
JOIN room_messages m ON m.id = a.message_id
WHERE m.created_at < :cutoff
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to query retained room attachment keys.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function storageKeysForDeletedAttachments(DateTimeImmutable $cutoff): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT storage_key
FROM attachments
WHERE deleted_at IS NOT NULL
  AND deleted_at < :cutoff
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to query deleted attachment keys.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function countRealtimeEvents(DateTimeImmutable $cutoff): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM realtime_events
WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
   OR created_at < :cutoff
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to count realtime events.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return (int) $statement->fetchColumn();
    }

    private function deleteRealtimeEvents(DateTimeImmutable $cutoff): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM realtime_events
WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
   OR created_at < :cutoff
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to delete realtime events.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return $statement->rowCount();
    }

    private function countExpiredPresence(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM room_presence WHERE expires_at <= NOW()');
        if ($statement === false) {
            throw new RuntimeException('Unable to count expired presence leases.');
        }

        return (int) $statement->fetchColumn();
    }

    private function deleteExpiredPresence(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM room_presence WHERE expires_at <= NOW()');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare expired presence cleanup.');
        }
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return list<string> */
    private function orphanPaths(DateTimeImmutable $cutoff): array
    {
        if (!is_dir($this->config->attachmentStoragePath)) {
            return [];
        }

        $knownKeys = [];
        $statement = $this->pdo->query('SELECT storage_key FROM attachments');
        if ($statement === false) {
            throw new RuntimeException('Unable to query tracked attachment keys.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $storageKey) {
            $knownKeys[(string) $storageKey] = true;
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->config->attachmentStoragePath,
                FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (preg_match('/\A[0-9a-f]{64}\z/D', $name) !== 1 || isset($knownKeys[$name])) {
                continue;
            }
            if ($file->getMTime() > $cutoff->getTimestamp()) {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    private function storagePath(string $storageKey): string
    {
        return $this->config->attachmentStoragePath
            . DIRECTORY_SEPARATOR . substr($storageKey, 0, 2)
            . DIRECTORY_SEPARATOR . substr($storageKey, 2, 2)
            . DIRECTORY_SEPARATOR . $storageKey;
    }

    /** @param array<string, int|bool> $result */
    private function removeFile(string $path, array &$result): void
    {
        if (!is_file($path)) {
            return;
        }
        if (@unlink($path)) {
            $result['files_removed'] = (int) $result['files_removed'] + 1;
        } else {
            $result['file_removal_failures'] = (int) $result['file_removal_failures'] + 1;
        }
    }

    private function removeEmptyShardDirectories(): void
    {
        if (!is_dir($this->config->attachmentStoragePath)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->config->attachmentStoragePath,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
    }
}
