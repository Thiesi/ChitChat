<?php

declare(strict_types=1);

namespace ChitChat\Maintenance;

use ChitChat\Config;
use ChitChat\Observability\MaintenanceRunRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class MaintenanceCoordinator
{
    private readonly CleanupService $cleanup;
    private readonly MaintenanceRunRepository $runs;

    public function __construct(
        private readonly PDO $pdo,
        Config $config,
    ) {
        $this->cleanup = new CleanupService($pdo, $config);
        $this->runs = new MaintenanceRunRepository($pdo);
    }

    /** @return array<string, int|bool> */
    public function run(bool $dryRun): array
    {
        $started = microtime(true);
        $runId = $this->runs->start($dryRun);

        try {
            $result = $this->cleanup->run($dryRun);
            $result['expired_sse_connections'] = $this->expiredSseConnections($dryRun);
            $result['maintenance_run_rows'] = $this->oldMaintenanceRuns($dryRun, $runId);
            $durationMs = self::durationMs($started);
            $fileFailures = (int) ($result['file_removal_failures'] ?? 0);
            if ($fileFailures > 0) {
                $this->runs->warn(
                    $runId,
                    $result,
                    sprintf('%d attachment file removal operation(s) failed.', $fileFailures),
                    $durationMs,
                );
            } else {
                $this->runs->succeed($runId, $result, $durationMs);
            }

            return $result;
        } catch (Throwable $exception) {
            try {
                $this->runs->fail($runId, $exception->getMessage(), self::durationMs($started));
            } catch (Throwable $recordingFailure) {
                error_log($recordingFailure->__toString());
            }
            throw $exception;
        }
    }

    private function expiredSseConnections(bool $dryRun): int
    {
        if ($dryRun) {
            return $this->scalarCount('SELECT COUNT(*) FROM sse_connections WHERE lease_expires_at <= NOW()');
        }

        $statement = $this->pdo->prepare('DELETE FROM sse_connections WHERE lease_expires_at <= NOW()');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare expired SSE-connection cleanup.');
        }
        $statement->execute();

        return $statement->rowCount();
    }

    private function oldMaintenanceRuns(bool $dryRun, int $currentRunId): int
    {
        $cutoff = new DateTimeImmutable('-400 days');
        $sql = $dryRun
            ? 'SELECT COUNT(*) FROM maintenance_runs WHERE id <> :current_id AND started_at < :cutoff'
            : 'DELETE FROM maintenance_runs WHERE id <> :current_id AND started_at < :cutoff';
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare old maintenance-run cleanup.');
        }
        $statement->execute([
            'current_id' => $currentRunId,
            'cutoff' => $cutoff->format(DATE_ATOM),
        ]);

        return $dryRun ? (int) $statement->fetchColumn() : $statement->rowCount();
    }

    private function scalarCount(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to execute operational cleanup count.');
        }

        return (int) $statement->fetchColumn();
    }

    private static function durationMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }
}
