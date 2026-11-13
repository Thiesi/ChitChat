<?php

declare(strict_types=1);

namespace ChitChat\Backup;

use ChitChat\Config;
use ChitChat\Database;
use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Records a run of bin/chitchat-backup for System status (#310), best-effort and after the
 * dump. A backup must work whether or not the database can be written (a read-only or broken
 * database, a backup role without INSERT), so this never throws: it uses its own short
 * connection and transaction, and any failure is one warning line. The backup's own exit code
 * and output never change. An unrecorded run shows as "unknown" on the status page.
 */
final class BackupRunRecorder
{
    /** Runs kept; older rows are pruned with each new one. */
    public const KEEP = 100;

    /**
     * @param Closure(): PDO $connect a fresh connection for this one write
     * @param Closure(string): void $warn where the warning goes (stderr in the CLI)
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly Closure $warn,
    ) {
    }

    public static function forConfig(Config $config): self
    {
        return new self(
            static fn (): PDO => Database::connect($config, 5),
            static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            },
        );
    }

    /**
     * @param 'success'|'failure' $outcome
     * @param 'live'|'offline' $consistency
     * @param ?string $setName the backup set's directory name; a path is reduced to its basename
     * @param ?string $errorKind a short fixed category on failure, never a message
     */
    public function record(
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        string $outcome,
        string $consistency,
        ?int $sizeBytes,
        ?string $setName,
        ?string $errorKind,
        string $applicationVersion,
    ): bool {
        $pdo = null;
        try {
            $pdo = ($this->connect)();
            $pdo->exec("SET lock_timeout = '5s'");
            $pdo->exec("SET statement_timeout = '10s'");
            $pdo->beginTransaction();
            $insert = $pdo->prepare(<<<'SQL'
INSERT INTO backup_runs (started_at, finished_at, outcome, consistency, size_bytes, set_name, error_kind, application_version)
VALUES (:started_at, :finished_at, :outcome, :consistency, :size_bytes, :set_name, :error_kind, :application_version)
SQL);
            if ($insert === false) {
                throw new BackupException('Unable to prepare the backup run record.');
            }
            $insert->bindValue(':started_at', $startedAt->format('Y-m-d H:i:s.uP'));
            $insert->bindValue(':finished_at', max($startedAt, $finishedAt)->format('Y-m-d H:i:s.uP'));
            $insert->bindValue(':outcome', $outcome);
            $insert->bindValue(':consistency', $consistency);
            $insert->bindValue(':size_bytes', $sizeBytes, $sizeBytes === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(':set_name', $setName === null ? null : substr(basename($setName), 0, 128), $setName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':error_kind', $outcome === 'success' ? null : ($errorKind ?? 'unexpected'), $outcome === 'success' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':application_version', substr($applicationVersion, 0, 64));
            $insert->execute();
            $pdo->exec('DELETE FROM backup_runs WHERE id NOT IN (SELECT id FROM backup_runs ORDER BY id DESC LIMIT ' . self::KEEP . ')');
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            try {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // The connection is gone; nothing to undo.
            }
            try {
                ($this->warn)('Warning: backup run not recorded (' . $exception::class . '). The backup itself is unaffected.');
            } catch (Throwable) {
                // Even the warning must not change the backup's outcome.
            }

            return false;
        }
    }

    /** The total size of a backup set's files. */
    public static function setSize(string $backupPath): ?int
    {
        try {
            $total = 0;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($backupPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                    $total += (int) $file->getSize();
                }
            }

            return $total;
        } catch (Throwable) {
            return null;
        }
    }
}
