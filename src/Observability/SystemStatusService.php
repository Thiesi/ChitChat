<?php

declare(strict_types=1);
namespace ChitChat\Observability;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Admin\MaintenanceMaxAge;
use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Backup\BackupRunRepository;
use ChitChat\Config;
use ChitChat\WebPush\PushDeliveryQueue;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SystemStatusService
{
    /** A due push waiting longer than this means no dispatcher is working (#310). */
    public const PUSH_OVERDUE_AFTER_SECONDS = 300;

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
        $rateLimitDecisions = array_map(
            static fn (array $row): array => [
                'policy' => (string) $row['scope'],
                'allowed' => (int) $row['allowed_count'],
                'rejected' => (int) $row['rejected_count'],
                'last_allowed_at' => $row['last_allowed_at'] === null ? null : (string) $row['last_allowed_at'],
                'last_rejected_at' => $row['last_rejected_at'] === null ? null : (string) $row['last_rejected_at'],
            ],
            $this->rows(<<<'SQL'
SELECT scope, allowed_count, rejected_count, last_allowed_at, last_rejected_at
FROM rate_limit_counters
ORDER BY scope
SQL),
        );

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
        // The Operational setting, or MAINTENANCE_MAX_AGE_HOURS until one is saved (#312).
        $maintenanceMaxAge = MaintenanceMaxAge::read($this->pdo, $this->config);
        $maintenanceOverdue = $successAgeSeconds === null
            || $successAgeSeconds > $maintenanceMaxAge['hours'] * 3600;

        return [
            'application' => [
                'name' => (new ApplicationNameService($this->pdo, $this->config))->effective(),
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
                'totp' => (new \ChitChat\Auth\Totp\TotpKeyHealth($this->pdo, $this->config))->status(),
                'failed_logins_24h' => (int) $security['failed_logins_24h'],
                'rate_limit_rows' => (int) $security['rate_limit_rows'],
                'rate_limit_policies' => (new RegistrationProtectionService($this->pdo, $this->config))->rateLimits()->toArray(),
                'rate_limit_decisions' => $rateLimitDecisions,
            ],
            'maintenance' => [
                'latest_run' => $latestRun,
                'latest_successful_destructive_run' => $latestSuccess,
                'latest_success_age_seconds' => $successAgeSeconds,
                'maximum_age_hours' => $maintenanceMaxAge['hours'],
                'maximum_age_source' => $maintenanceMaxAge['source'],
                'overdue' => $maintenanceOverdue,
            ],
            'push' => $this->push(),
            'backups' => $this->backups(),
            'metrics' => [
                'enabled' => $this->config->metricsBearerToken !== '',
            ],
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * Push queue health (#310). "Overdue" means a delivery that is already due has waited longer
     * than PUSH_OVERDUE_AFTER_SECONDS: the cron sweep runs every minute and the listener every
     * 15 s, so neither is working. A stopped dispatcher with nothing due loses nothing and only
     * shows once something is due. Push not configured is neutral, never an error.
     *
     * @return array<string, mixed>
     */
    private function push(): array
    {
        $configured = $this->config->webPushEnabled();
        $queue = new PushDeliveryQueue($this->pdo);
        $now = new DateTimeImmutable();
        $health = $queue->health($now);
        $freshness = $queue->freshness($now);
        $overdue = $configured
            && $freshness['oldest_due_seconds'] !== null
            && $freshness['oldest_due_seconds'] > self::PUSH_OVERDUE_AFTER_SECONDS;

        return [
            'configured' => $configured,
            'status' => !$configured ? 'not_configured' : ($overdue ? 'overdue' : 'ok'),
            'pending_deliveries' => $health['pending_deliveries'] ?? 0,
            'due_deliveries' => $health['due_deliveries'] ?? 0,
            'failed_deliveries' => $health['failed_deliveries'] ?? 0,
            'oldest_pending_seconds' => $health['oldest_pending_seconds'] ?? 0,
            'oldest_due_seconds' => $freshness['oldest_due_seconds'],
            'last_sent_at' => $freshness['last_sent_at'],
            'overdue_after_seconds' => self::PUSH_OVERDUE_AFTER_SECONDS,
            'overdue' => $overdue,
        ];
    }

    /**
     * Backup freshness (#310), from the runs bin/chitchat-backup recorded. A run it couldn't
     * record (a read-only or broken database) is missing, so the status is "unknown", never "ok";
     * while tracking is on, that also counts as overdue.
     *
     * @return array<string, mixed>
     */
    private function backups(): array
    {
        $runs = new BackupRunRepository($this->pdo);
        $maximumAgeHours = $runs->maximumAgeHours();
        $tracking = $maximumAgeHours > 0;
        $latest = $runs->latest();
        $success = $runs->latestSuccess();
        $successAge = $this->ageSeconds($success['finished_at'] ?? null);
        $overdue = $tracking && ($successAge === null || $successAge > $maximumAgeHours * 3600);

        return [
            'tracking_enabled' => $tracking,
            'maximum_age_hours' => $maximumAgeHours,
            'status' => self::backupStatus($tracking, $latest, $overdue),
            'latest_run' => $latest,
            'latest_success' => $success,
            'latest_success_age_seconds' => $successAge,
            'overdue' => $overdue,
        ];
    }

    /** @param ?array<string, mixed> $latest */
    public static function backupStatus(bool $tracking, ?array $latest, bool $overdue): string
    {
        return match (true) {
            !$tracking => 'not_tracked',
            $latest === null => 'unknown',
            $latest['outcome'] !== 'success' => 'failed',
            $overdue => 'overdue',
            default => 'ok',
        };
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $rows = $this->rows($sql);
        $row = $rows[0] ?? null;
        if ($row === null) {
            throw new RuntimeException('System status query returned no row.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to query system status.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    private function ageSeconds(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return max(0, time() - (new DateTimeImmutable($value))->getTimestamp());
    }
}
