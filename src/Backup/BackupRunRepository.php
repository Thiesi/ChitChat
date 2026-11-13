<?php

declare(strict_types=1);

namespace ChitChat\Backup;

use PDO;
use RuntimeException;

/** What System status shows about backups (#310), from the runs bin/chitchat-backup recorded. */
final class BackupRunRepository
{
    private const COLUMNS = 'started_at, finished_at, outcome, consistency, size_bytes, set_name, error_kind';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return ?array{started_at:string, finished_at:string, outcome:string, consistency:string, size_bytes:?int, set_name:?string, error_kind:?string} */
    public function latest(): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM backup_runs ORDER BY finished_at DESC, id DESC LIMIT 1');
    }

    /** @return ?array{started_at:string, finished_at:string, outcome:string, consistency:string, size_bytes:?int, set_name:?string, error_kind:?string} */
    public function latestSuccess(): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . " FROM backup_runs WHERE outcome = 'success' ORDER BY finished_at DESC, id DESC LIMIT 1");
    }

    public function maximumAgeHours(): int
    {
        $statement = $this->pdo->query('SELECT backup_max_age_hours FROM system_settings WHERE id = 1');
        $value = $statement === false ? false : $statement->fetchColumn();
        if ($value === false || $value === null) {
            throw new RuntimeException('System settings are missing.');
        }

        return (int) $value;
    }

    /** @return ?array{started_at:string, finished_at:string, outcome:string, consistency:string, size_bytes:?int, set_name:?string, error_kind:?string} */
    private function one(string $sql): ?array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to read backup runs.');
        }
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'started_at' => (string) $row['started_at'],
            'finished_at' => (string) $row['finished_at'],
            'outcome' => (string) $row['outcome'],
            'consistency' => (string) $row['consistency'],
            'size_bytes' => $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
            'set_name' => $row['set_name'] === null ? null : (string) $row['set_name'],
            'error_kind' => $row['error_kind'] === null ? null : (string) $row['error_kind'],
        ];
    }
}
