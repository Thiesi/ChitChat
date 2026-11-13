<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Observability\PrometheusEncoder;
use PHPUnit\Framework\TestCase;

final class PrometheusEncoderTest extends TestCase
{
    public function testStatusSnapshotIsEncodedAsPrometheusText(): void
    {
        $output = PrometheusEncoder::encode([
            'application' => ['version' => '1.1.0-dev"test', 'environment' => "test\nci"],
            'database' => ['query_latency_ms' => 12.5, 'size_bytes' => 1024],
            'attachments' => [
                'active_files' => 3,
                'deleted_files' => 1,
                'tracked_bytes' => 2048,
                'disk_total_bytes' => 4096,
                'disk_free_bytes' => 1024,
            ],
            'realtime' => [
                'active_sse_connections' => 4,
                'active_sse_users' => 2,
                'active_presence_leases' => 5,
                'active_presence_users' => 3,
                'retained_events' => 99,
            ],
            'security' => [
                'failed_logins_24h' => 7,
                'rate_limit_rows' => 8,
                'rate_limit_decisions' => [
                    ['policy' => 'room_send', 'allowed' => 41, 'rejected' => 2],
                ],
            ],
            'maintenance' => [
                'overdue' => false,
                'latest_run' => [
                    'status' => 'success',
                    'duration_ms' => 250,
                    'finished_at' => '2026-07-17T10:00:00+00:00',
                ],
                'latest_successful_destructive_run' => [
                    'finished_at' => '2026-07-17T10:00:00+00:00',
                ],
            ],
        ]);

        self::assertStringContainsString('chitchat_info{version="1.1.0-dev\\"test",environment="test\\nci"} 1', $output);
        self::assertStringContainsString('chitchat_database_query_latency_seconds 0.0125', $output);
        self::assertStringContainsString('chitchat_attachment_files{state="active"} 3', $output);
        self::assertStringContainsString('chitchat_sse_connections 4', $output);
        self::assertStringContainsString('chitchat_failed_logins_24h 7', $output);
        self::assertStringContainsString(
            'chitchat_rate_limit_decisions_total{policy="room_send",outcome="allowed"} 41',
            $output,
        );
        self::assertStringContainsString(
            'chitchat_rate_limit_decisions_total{policy="room_send",outcome="rejected"} 2',
            $output,
        );
        self::assertStringContainsString('chitchat_maintenance_overdue 0', $output);
        self::assertStringContainsString('chitchat_maintenance_last_run_status{status="success"} 1', $output);
        self::assertStringContainsString('chitchat_maintenance_last_run_duration_seconds 0.25', $output);
        self::assertStringEndsWith("\n", $output);
    }

    public function testPushAndBackupHealthAreEncoded(): void
    {
        $output = PrometheusEncoder::encode([
            'push' => [
                'configured' => true, 'pending_deliveries' => 4, 'due_deliveries' => 2, 'failed_deliveries' => 1,
                'oldest_pending_seconds' => 900, 'oldest_due_seconds' => 420, 'overdue' => true,
                'last_sent_at' => '2026-11-13T05:00:00+00:00',
            ],
            'backups' => [
                'tracking_enabled' => true, 'overdue' => false,
                'latest_run' => ['outcome' => 'failure', 'finished_at' => '2026-11-13T03:00:00+00:00'],
                'latest_success' => ['outcome' => 'success', 'finished_at' => '2026-11-12T02:46:00+00:00'],
            ],
        ]);

        foreach ([
            'chitchat_push_configured 1',
            'chitchat_push_deliveries{state="pending"} 4',
            'chitchat_push_deliveries{state="due"} 2',
            'chitchat_push_deliveries{state="failed"} 1',
            'chitchat_push_oldest_due_seconds 420',
            'chitchat_push_oldest_pending_seconds 900',
            'chitchat_push_overdue 1',
            'chitchat_push_last_sent_timestamp_seconds 1794546000',
            'chitchat_backup_tracking_enabled 1',
            'chitchat_backup_overdue 0',
            'chitchat_backup_last_run_success 0',
            'chitchat_backup_last_run_timestamp_seconds 1794538800',
            'chitchat_backup_last_success_timestamp_seconds 1794451560',
        ] as $line) {
            self::assertStringContainsString($line . "\n", $output);
        }
    }

    public function testMissingPushAndBackupDataIsZero(): void
    {
        $output = PrometheusEncoder::encode(['push' => ['configured' => false, 'oldest_due_seconds' => null], 'backups' => ['latest_run' => null]]);
        foreach (['chitchat_push_configured 0', 'chitchat_push_oldest_due_seconds 0', 'chitchat_push_last_sent_timestamp_seconds 0',
            'chitchat_backup_last_run_success 0', 'chitchat_backup_last_success_timestamp_seconds 0'] as $line) {
            self::assertStringContainsString($line . "\n", $output);
        }
    }
}
