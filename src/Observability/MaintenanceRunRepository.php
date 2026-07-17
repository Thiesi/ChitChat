<?php

declare(strict_types=1);

namespace ChitChat\Observability;

use JsonException;
use PDO;
use RuntimeException;

final class MaintenanceRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(bool $dryRun): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO maintenance_runs (dry_run, status)
VALUES (:dry_run, 'running')
RETURNING id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance-run creation.');
        }
        $statement->execute(['dry_run' => $dryRun]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Maintenance-run creation returned no ID.');
        }

        return (int) $id;
    }

    /** @param array<string, int|bool> $result */
    public function succeed(int $id, array $result, int $durationMs): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE maintenance_runs
SET status = 'success',
    finished_at = NOW(),
    duration_ms = :duration_ms,
    result_json = CAST(:result_json AS jsonb),
    error_message = NULL
WHERE id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance-run success update.');
        }
        $statement->execute([
            'id' => $id,
            'duration_ms' => $durationMs,
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Maintenance-run success update changed no row.');
        }
    }

    public function fail(int $id, string $message, int $durationMs): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE maintenance_runs
SET status = 'failure',
    finished_at = NOW(),
    duration_ms = :duration_ms,
    result_json = NULL,
    error_message = :error_message
WHERE id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare maintenance-run failure update.');
        }
        $statement->execute([
            'id' => $id,
            'duration_ms' => $durationMs,
            'error_message' => mb_substr($message, 0, 2000, 'UTF-8'),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Maintenance-run failure update changed no row.');
        }
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        return $this->fetchOne(<<<'SQL'
SELECT id, dry_run::int AS dry_run, status, started_at, finished_at, duration_ms,
       result_json::text AS result_json, error_message
FROM maintenance_runs
ORDER BY id DESC
LIMIT 1
SQL);
    }

    /** @return array<string, mixed>|null */
    public function latestSuccessfulDestructive(): ?array
    {
        return $this->fetchOne(<<<'SQL'
SELECT id, dry_run::int AS dry_run, status, started_at, finished_at, duration_ms,
       result_json::text AS result_json, error_message
FROM maintenance_runs
WHERE status = 'success' AND dry_run = FALSE
ORDER BY id DESC
LIMIT 1
SQL);
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(string $sql): ?array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to query maintenance-run status.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $result = null;
        if ($row['result_json'] !== null) {
            try {
                $decoded = json_decode((string) $row['result_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored maintenance result is invalid.', 0, $exception);
            }
            if (!is_array($decoded)) {
                throw new RuntimeException('Stored maintenance result must be an object.');
            }
            $result = $decoded;
        }

        return [
            'id' => (int) $row['id'],
            'dry_run' => (int) $row['dry_run'] === 1,
            'status' => (string) $row['status'],
            'started_at' => (string) $row['started_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'result' => $result,
            'error_message' => $row['error_message'] === null ? null : (string) $row['error_message'],
        ];
    }
}
