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
}
