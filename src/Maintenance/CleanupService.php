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

final class CleanupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /** @return array<string, int|bool> */
    public function run(bool $dryRun): array
    {
        if (!$this->lock()) {
            throw new RuntimeException('Another maintenance cleanup is already running.');
        }

        try {
            $settings = $this->settings();
            $cutoffs = [
                'room' => $this->optionalDays($settings['room_message_retention_days']),
                'direct' => $this->optionalDays($settings['direct_message_retention_days']),
                'audit' => $this->optionalDays($settings['audit_retention_days']),
                'deleted_attachment' => $this->optionalDays($settings['deleted_attachment_retention_days']),
                'event' => new DateTimeImmutable('-' . $settings['realtime_event_retention_hours'] . ' hours'),
                'login' => new DateTimeImmutable('-' . $settings['login_attempt_retention_days'] . ' days'),
                'rate_limit' => new DateTimeImmutable('-2 days'),
                'orphan' => new DateTimeImmutable('-' . $settings['orphan_attachment_grace_hours'] . ' hours'),
            ];

            $roomKeys = $cutoffs['room'] instanceof DateTimeImmutable
                ? $this->keysForRoomRetention($cutoffs['room'])
                : [];
            $directKeys = $cutoffs['direct'] instanceof DateTimeImmutable
                ? $this->keysForDirectRetention($cutoffs['direct'])
                : [];
            $deletedKeys = $cutoffs['deleted_attachment'] instanceof DateTimeImmutable
                ? $this->keysForDeletedAttachments($cutoffs['deleted_attachment'])
                : [];
            $orphanPaths = $this->orphanPaths($cutoffs['orphan']);
            $trackedKeys = array_unique(array_merge($roomKeys, $directKeys, $deletedKeys));

            $result = [
                'dry_run' => $dryRun,
                'direct_messages' => $this->optionalCount(
                    'SELECT COUNT(*) FROM direct_messages WHERE created_at < :cutoff',
                    $cutoffs['direct'],
                ),
                'room_messages' => $this->optionalCount(
                    'SELECT COUNT(*) FROM room_messages WHERE created_at < :cutoff',
                    $cutoffs['room'],
                ),
                'deleted_attachments' => count($deletedKeys),
                'audit_entries' => $this->optionalCount(
                    'SELECT COUNT(*) FROM audit_log WHERE created_at < :cutoff',
                    $cutoffs['audit'],
                ),
                'realtime_events' => $this->count(
                    'SELECT COUNT(*) FROM realtime_events WHERE (expires_at IS NOT NULL AND expires_at <= NOW()) OR created_at < :cutoff',
                    $cutoffs['event'],
                ),
                'login_attempts' => $this->count(
                    'SELECT COUNT(*) FROM login_attempts WHERE created_at < :cutoff',
                    $cutoffs['login'],
                ),
                'rate_limit_rows' => $this->count(
                    'SELECT COUNT(*) FROM request_rate_limits WHERE updated_at < :cutoff',
                    $cutoffs['rate_limit'],
                ),
                'expired_presence_leases' => $this->scalarCount(
                    'SELECT COUNT(*) FROM room_presence WHERE lease_expires_at <= NOW()',
                ),
                'tracked_files' => count($trackedKeys),
                'orphan_files' => count($orphanPaths),
                'files_removed' => 0,
                'file_removal_failures' => 0,
            ];

            if ($dryRun) {
                return $result;
            }

            $this->pdo->beginTransaction();
            try {
                $result['direct_messages'] = $this->optionalDelete(
                    'DELETE FROM direct_messages WHERE created_at < :cutoff',
                    $cutoffs['direct'],
                );
                $result['room_messages'] = $this->optionalDelete(
                    'DELETE FROM room_messages WHERE created_at < :cutoff',
                    $cutoffs['room'],
                );
                $result['deleted_attachments'] = $this->optionalDelete(
                    'DELETE FROM attachments WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff',
                    $cutoffs['deleted_attachment'],
                ) + $this->optionalDelete(
                    'DELETE FROM direct_message_attachments WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff',
                    $cutoffs['deleted_attachment'],
                );
                $result['audit_entries'] = $this->optionalDelete(
                    'DELETE FROM audit_log WHERE created_at < :cutoff',
                    $cutoffs['audit'],
                );
                $result['realtime_events'] = $this->delete(
                    'DELETE FROM realtime_events WHERE (expires_at IS NOT NULL AND expires_at <= NOW()) OR created_at < :cutoff',
                    $cutoffs['event'],
                );
                $result['login_attempts'] = $this->delete(
                    'DELETE FROM login_attempts WHERE created_at < :cutoff',
                    $cutoffs['login'],
                );
                $result['rate_limit_rows'] = $this->delete(
                    'DELETE FROM request_rate_limits WHERE updated_at < :cutoff',
                    $cutoffs['rate_limit'],
                );
                $presence = $this->pdo->prepare('DELETE FROM room_presence WHERE lease_expires_at <= NOW()');
                if ($presence === false) {
                    throw new RuntimeException('Unable to prepare expired-presence cleanup.');
                }
                $presence->execute();
                $result['expired_presence_leases'] = $presence->rowCount();
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            foreach ($trackedKeys as $key) {
                $this->remove($this->pathForKey($key), $result);
            }
            foreach ($orphanPaths as $path) {
                $this->remove($path, $result);
            }
            $this->removeEmptyDirectories();

            (new AuditLogger($this->pdo))->log(
                null,
                'maintenance.cleanup',
                'system',
                null,
                $result,
                '127.0.0.1',
            );

            return $result;
        } finally {
            $this->unlock();
        }
    }

    private function lock(): bool
    {
        $statement = $this->pdo->query(
            "SELECT pg_try_advisory_lock(hashtext('chitchat-maintenance-cleanup'))::int",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to acquire maintenance lock.');
        }

        return (int) $statement->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        $this->pdo->query("SELECT pg_advisory_unlock(hashtext('chitchat-maintenance-cleanup'))");
    }

    /** @return array<string, int> */
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
            throw new RuntimeException('Unable to query maintenance settings.');
        }
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Maintenance settings are missing.');
        }

        $settings = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $settings[$key] = (int) $value;
            }
        }

        return $settings;
    }

    private function optionalDays(int $days): ?DateTimeImmutable
    {
        return $days === 0 ? null : new DateTimeImmutable('-' . $days . ' days');
    }

    private function scalarCount(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to execute maintenance count.');
        }

        return (int) $statement->fetchColumn();
    }

    private function optionalCount(string $sql, mixed $cutoff): int
    {
        return $cutoff instanceof DateTimeImmutable ? $this->count($sql, $cutoff) : 0;
    }

    private function count(string $sql, DateTimeImmutable $cutoff): int
    {
        $statement = $this->prepareWithCutoff($sql, $cutoff);

        return (int) $statement->fetchColumn();
    }

    private function optionalDelete(string $sql, mixed $cutoff): int
    {
        return $cutoff instanceof DateTimeImmutable ? $this->delete($sql, $cutoff) : 0;
    }

    private function delete(string $sql, DateTimeImmutable $cutoff): int
    {
        return $this->prepareWithCutoff($sql, $cutoff)->rowCount();
    }

    private function prepareWithCutoff(string $sql, DateTimeImmutable $cutoff): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance query.');
        }
        $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);

        return $statement;
    }

    /** @return list<string> */
    private function keysForRoomRetention(DateTimeImmutable $cutoff): array
    {
        return $this->columnStrings(
            'SELECT a.storage_key FROM attachments a JOIN room_messages m ON m.id = a.message_id WHERE m.created_at < :cutoff',
            $cutoff,
        );
    }

    /** @return list<string> */
    private function keysForDirectRetention(DateTimeImmutable $cutoff): array
    {
        return $this->columnStrings(
            'SELECT a.storage_key FROM direct_message_attachments a JOIN direct_messages m ON m.id = a.direct_message_id WHERE m.created_at < :cutoff',
            $cutoff,
        );
    }

    /** @return list<string> */
    private function keysForDeletedAttachments(DateTimeImmutable $cutoff): array
    {
        return $this->columnStrings(<<<'SQL'
SELECT storage_key
FROM attachments
WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff
UNION
SELECT storage_key
FROM direct_message_attachments
WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff
SQL, $cutoff);
    }

    /** @return list<string> */
    private function columnStrings(string $sql, DateTimeImmutable $cutoff): array
    {
        $values = $this->prepareWithCutoff($sql, $cutoff)->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $values));
    }

    /** @return list<string> */
    private function orphanPaths(DateTimeImmutable $cutoff): array
    {
        if (!is_dir($this->config->attachmentStoragePath)) {
            return [];
        }
        $known = [];
        $statement = $this->pdo->query(<<<'SQL'
SELECT storage_key FROM attachments
UNION
SELECT storage_key FROM direct_message_attachments
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to query tracked attachment keys.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $known[(string) $value] = true;
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->config->attachmentStoragePath,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            $key = $file->getFilename();
            if (
                $file->isFile()
                && preg_match('/\A[0-9a-f]{64}\z/D', $key) === 1
                && !isset($known[$key])
                && $file->getMTime() <= $cutoff->getTimestamp()
            ) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    private function pathForKey(string $key): string
    {
        return $this->config->attachmentStoragePath
            . DIRECTORY_SEPARATOR . substr($key, 0, 2)
            . DIRECTORY_SEPARATOR . substr($key, 2, 2)
            . DIRECTORY_SEPARATOR . $key;
    }

    /** @param array<string, int|bool> $result */
    private function remove(string $path, array &$result): void
    {
        if (!is_file($path)) {
            return;
        }
        $field = @unlink($path) ? 'files_removed' : 'file_removal_failures';
        $result[$field] = (int) $result[$field] + 1;
    }

    private function removeEmptyDirectories(): void
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
