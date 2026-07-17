<?php

declare(strict_types=1);

namespace ChitChat\Observability;

use DateTimeImmutable;

final class PrometheusEncoder
{
    /** @param array<string, mixed> $status */
    public static function encode(array $status): string
    {
        $application = self::section($status, 'application');
        $database = self::section($status, 'database');
        $attachments = self::section($status, 'attachments');
        $realtime = self::section($status, 'realtime');
        $security = self::section($status, 'security');
        $maintenance = self::section($status, 'maintenance');
        $latestRun = is_array($maintenance['latest_run'] ?? null) ? $maintenance['latest_run'] : null;
        $latestSuccess = is_array($maintenance['latest_successful_destructive_run'] ?? null)
            ? $maintenance['latest_successful_destructive_run']
            : null;

        $lines = [
            '# HELP chitchat_info Static application information.',
            '# TYPE chitchat_info gauge',
            sprintf(
                'chitchat_info{version="%s",environment="%s"} 1',
                self::label((string) ($application['version'] ?? 'unknown')),
                self::label((string) ($application['environment'] ?? 'unknown')),
            ),
            '# HELP chitchat_database_query_latency_seconds Latency of the status database query.',
            '# TYPE chitchat_database_query_latency_seconds gauge',
            'chitchat_database_query_latency_seconds ' . self::decimal(self::number($database, 'query_latency_ms') / 1000),
            '# HELP chitchat_database_size_bytes Current PostgreSQL database size.',
            '# TYPE chitchat_database_size_bytes gauge',
            'chitchat_database_size_bytes ' . self::integer($database, 'size_bytes'),
            '# HELP chitchat_attachment_files Attachment metadata rows by state.',
            '# TYPE chitchat_attachment_files gauge',
            'chitchat_attachment_files{state="active"} ' . self::integer($attachments, 'active_files'),
            'chitchat_attachment_files{state="deleted"} ' . self::integer($attachments, 'deleted_files'),
            '# HELP chitchat_attachment_tracked_bytes Total bytes represented by attachment metadata.',
            '# TYPE chitchat_attachment_tracked_bytes gauge',
            'chitchat_attachment_tracked_bytes ' . self::integer($attachments, 'tracked_bytes'),
            '# HELP chitchat_attachment_disk_bytes Attachment filesystem capacity by state.',
            '# TYPE chitchat_attachment_disk_bytes gauge',
            'chitchat_attachment_disk_bytes{state="total"} ' . self::integer($attachments, 'disk_total_bytes'),
            'chitchat_attachment_disk_bytes{state="free"} ' . self::integer($attachments, 'disk_free_bytes'),
            '# HELP chitchat_sse_connections Active leased SSE connections.',
            '# TYPE chitchat_sse_connections gauge',
            'chitchat_sse_connections ' . self::integer($realtime, 'active_sse_connections'),
            '# HELP chitchat_sse_users Accounts with at least one active SSE connection.',
            '# TYPE chitchat_sse_users gauge',
            'chitchat_sse_users ' . self::integer($realtime, 'active_sse_users'),
            '# HELP chitchat_presence_leases Active room-presence leases.',
            '# TYPE chitchat_presence_leases gauge',
            'chitchat_presence_leases ' . self::integer($realtime, 'active_presence_leases'),
            '# HELP chitchat_presence_users Accounts with active room presence.',
            '# TYPE chitchat_presence_users gauge',
            'chitchat_presence_users ' . self::integer($realtime, 'active_presence_users'),
            '# HELP chitchat_realtime_events Retained realtime event rows.',
            '# TYPE chitchat_realtime_events gauge',
            'chitchat_realtime_events ' . self::integer($realtime, 'retained_events'),
            '# HELP chitchat_failed_logins_24h Failed login attempts recorded in the previous 24 hours.',
            '# TYPE chitchat_failed_logins_24h gauge',
            'chitchat_failed_logins_24h ' . self::integer($security, 'failed_logins_24h'),
            '# HELP chitchat_rate_limit_rows Current database-backed rate-limit rows.',
            '# TYPE chitchat_rate_limit_rows gauge',
            'chitchat_rate_limit_rows ' . self::integer($security, 'rate_limit_rows'),
            '# HELP chitchat_maintenance_overdue Whether no successful destructive maintenance run exists within the configured maximum age.',
            '# TYPE chitchat_maintenance_overdue gauge',
            'chitchat_maintenance_overdue ' . self::boolean($maintenance, 'overdue'),
            '# HELP chitchat_maintenance_last_run_status Status of the most recent maintenance invocation.',
            '# TYPE chitchat_maintenance_last_run_status gauge',
            'chitchat_maintenance_last_run_status{status="' . self::label((string) ($latestRun['status'] ?? 'none')) . '"} 1',
            '# HELP chitchat_maintenance_last_run_duration_seconds Duration of the most recent maintenance invocation.',
            '# TYPE chitchat_maintenance_last_run_duration_seconds gauge',
            'chitchat_maintenance_last_run_duration_seconds ' . self::decimal(((float) ($latestRun['duration_ms'] ?? 0)) / 1000),
            '# HELP chitchat_maintenance_last_run_timestamp_seconds Completion time of the most recent maintenance invocation.',
            '# TYPE chitchat_maintenance_last_run_timestamp_seconds gauge',
            'chitchat_maintenance_last_run_timestamp_seconds ' . self::timestamp($latestRun['finished_at'] ?? null),
            '# HELP chitchat_maintenance_last_success_timestamp_seconds Completion time of the most recent successful destructive maintenance run.',
            '# TYPE chitchat_maintenance_last_success_timestamp_seconds gauge',
            'chitchat_maintenance_last_success_timestamp_seconds ' . self::timestamp($latestSuccess['finished_at'] ?? null),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function section(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $section */
    private static function integer(array $section, string $key): string
    {
        $value = $section[$key] ?? 0;
        return (string) (is_int($value) || is_float($value) ? (int) $value : 0);
    }

    /** @param array<string, mixed> $section */
    private static function number(array $section, string $key): float
    {
        $value = $section[$key] ?? 0;
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /** @param array<string, mixed> $section */
    private static function boolean(array $section, string $key): string
    {
        return ($section[$key] ?? false) === true ? '1' : '0';
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', max(0.0, $value)), '0'), '.');
    }

    private static function timestamp(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '0';
        }

        return (string) (new DateTimeImmutable($value))->getTimestamp();
    }

    private static function label(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
