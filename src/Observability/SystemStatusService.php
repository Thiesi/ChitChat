<?php

declare(strict_types=1);

namespace ChitChat\Observability;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SystemStatusService
{
    private readonly MaintenanceRunRepository $maintenanceRuns;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->maintenanceRuns = new MaintenanceRunRepository($pdo);
    }

    /** @return array<string, mixed> */
    public function forAdministrator(AuthenticatedUser $actor): array
    {
        if (!$actor->canManageUsers()) {
            throw new ApiException(403, 'forbidden', 'System status requires Administrator access.');
        }

        return $this->snapshot();
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $databaseStarted = microtime(true);
        $database = $this->row(<<<'SQL'
SELECT pg_database_size(current_database())::bigint AS size_bytes,
       current_database() AS database_name
SQL);
        $databaseLatencyMs = max(0.0, (microtime(true) - $databaseStarted) * 1000);

        $attachments = $this->row(<<<'SQL'
SELECT COUNT(*)::bigint AS tracked_files,
       COUNT(*) FILTER (WHERE deleted_at IS NULL)::bigint AS active_files,
       COUNT(*) FILTER (WHERE deleted_at IS NOT NULL)::bigint AS deleted_files,
       COALESCE(SUM(size_bytes), 0)::bigint AS tracked_bytes
FROM (
    SELECT size_bytes, deleted_at FROM attachments
    UNION ALL
    SELECT size_bytes, deleted_at FROM direct_message_attachments
) tracked
SQL);

        $realtime = $this->row(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM sse_connections WHERE lease_expires_at > NOW())::bigint AS active_sse_connections,
    (SELECT COUNT(DISTINCT user_id) FROM sse_connections WHERE lease_expires_at > NOW())::bigint AS active_sse_users,
    (SELECT COUNT(*) FROM room_presence WHERE lease_expires_at > NOW())::bigint AS active_presence_leases,
    (SELECT COUNT(DISTINCT user_id) FROM room_presence WHERE lease_expires_at > NOW())::bigint AS active_presence_users,
    (SELECT COUNT(*) FROM realtime_events)::bigint AS retained_events
SQL);

        $security = $this->row(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM login_attempts WHERE successful = FALSE AND created_at >= NOW() - INTERVAL '24 hours')::bigint AS failed_logins_24h,
    (SELECT COUNT(*) FROM request_rate_limits)::bigint AS rate_limit_rows
SQL);

        $storageTotal = is_dir($this->config->attachmentStoragePath)
            ? disk_total_space($this->config->attachmentStoragePath)
            : false;
        $storageFree = is_dir($this->config->attachmentStoragePath)
            ? disk_free_space($this->config->attachmentStoragePath)
            : false;
        $totalBytes = $storageTotal === false ? null : (int) $storageTotal;
        $freeBytes = $storageFree === false ? null : (int) $storageFree;
        $usedPercent = null;
        if ($totalBytes !== null && $totalBytes > 0 && $freeBytes !== null) {
            $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 2);
        }

        $latestRun = $this->maintenanceRuns->latest();
        $latestSuccess = $this->maintenanceRuns->latestSuccessfulDestructive();
        $successAgeSeconds = $this->ageSeconds($latestSuccess['finished_at'] ?? null);
        $maintenanceOverdue = $successAgeSeconds === null
            || $successAgeSeconds > $this->config->maintenanceMaxAgeHours * 3600;

        return [
            'application' => [
                'name' => $this->config->applicationName,
                'version' => $this->config->applicationVersion,
                'environment' => $this->config->environment,
            ],
            'database' => [
                'name' => (string) $database['database_name'],
                'size_bytes' => (int) $database['size_bytes'],
                'query_latency_ms' => round($databaseLatencyMs, 3),
            ],
            'attachments' => [
                'tracked_files' => (int) $attachments['tracked_files'],
                'active_files' => (int) $attachments['active_files'],
                'deleted_files' => (int) $attachments['deleted_files'],
                'tracked_bytes' => (int) $attachments['tracked_bytes'],
                'storage_available' => $totalBytes !== null && $freeBytes !== null,
                'disk_total_bytes' => $totalBytes,
                'disk_free_bytes' => $freeBytes,
                'disk_used_percent' => $usedPercent,
            ],
            'realtime' => [
                'active_sse_connections' => (int) $realtime['active_sse_connections'],
                'active_sse_users' => (int) $realtime['active_sse_users'],
                'active_presence_leases' => (int) $realtime['active_presence_leases'],
                'active_presence_users' => (int) $realtime['active_presence_users'],
                'retained_events' => (int) $realtime['retained_events'],
            ],
            'security' => [
                'failed_logins_24h' => (int) $security['failed_logins_24h'],
                'rate_limit_rows' => (int) $security['rate_limit_rows'],
            ],
            'maintenance' => [
                'latest_run' => $latestRun,
                'latest_successful_destructive_run' => $latestSuccess,
                'latest_success_age_seconds' => $successAgeSeconds,
                'maximum_age_hours' => $this->config->maintenanceMaxAgeHours,
                'overdue' => $maintenanceOverdue,
            ],
            'metrics' => [
                'enabled' => $this->config->metricsBearerToken !== '',
            ],
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to query system status.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('System status query returned no row.');
        }

        return $row;
    }

    private function ageSeconds(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return max(0, time() - (new DateTimeImmutable($value))->getTimestamp());
    }
}
