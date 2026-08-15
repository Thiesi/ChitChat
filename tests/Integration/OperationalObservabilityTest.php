<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Maintenance\MaintenanceCoordinator;
use ChitChat\Observability\SseConnectionTracker;
use ChitChat\Observability\SystemStatusService;
use RuntimeException;

final class OperationalObservabilityTest extends DatabaseTestCase
{
    private ?string $storagePath = null;

    protected function tearDown(): void
    {
        if ($this->storagePath !== null) {
            @rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    public function testSseConnectionLeaseCanBeRefreshedAndClosed(): void
    {
        $config = $this->observabilityConfig();
        $root = (new AuthService($this->pdo, $config))->register(
            'Root',
            'a very secure password',
            '127.0.0.1',
        );
        $tracker = new SseConnectionTracker($this->pdo, 40);
        $connectionId = $tracker->open($root->id);

        self::assertSame(1, $this->activeSseConnections());
        $this->pdo->exec(
            "UPDATE sse_connections SET lease_expires_at = NOW() - INTERVAL '1 minute'",
        );
        self::assertSame(0, $this->activeSseConnections());

        $tracker->touch($connectionId);
        self::assertSame(1, $this->activeSseConnections());

        $tracker->close($connectionId);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sse_connections')->fetchColumn());
    }

    public function testAdministratorStatusReportsMaintenanceStorageAndSecurity(): void
    {
        $config = $this->observabilityConfig();
        $auth = new AuthService($this->pdo, $config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $tracker = new SseConnectionTracker($this->pdo, 40);
        $connectionId = $tracker->open($root->id);
        $this->pdo->exec(<<<'SQL'
INSERT INTO login_attempts (username_canonical, ip_address, successful, reason)
VALUES ('member', '127.0.0.2', FALSE, 'invalid_credentials')
SQL);
        $limiter = new RateLimiter($this->pdo, $config->rateLimits);
        $limiter->recordDecision('login', true);
        $limiter->recordDecision('login', false);

        $result = (new MaintenanceCoordinator($this->pdo, $config))->run(false);
        self::assertFalse($result['dry_run']);
        self::assertArrayHasKey('expired_sse_connections', $result);
        self::assertArrayHasKey('maintenance_run_rows', $result);

        $status = (new SystemStatusService($this->pdo, $config))->forAdministrator($root);
        self::assertSame('1.3.0', $status['application']['version']);
        self::assertGreaterThanOrEqual(0.0, $status['database']['query_latency_ms']);
        self::assertTrue($status['attachments']['storage_available']);
        self::assertSame(1, $status['realtime']['active_sse_connections']);
        self::assertSame(1, $status['security']['failed_logins_24h']);
        self::assertSame(30, $status['security']['rate_limit_policies']['room_send']['maximum_attempts']);
        self::assertSame(60, $status['security']['rate_limit_policies']['room_send']['window_seconds']);
        self::assertSame('login', $status['security']['rate_limit_decisions'][0]['policy']);
        self::assertSame(1, $status['security']['rate_limit_decisions'][0]['allowed']);
        self::assertSame(1, $status['security']['rate_limit_decisions'][0]['rejected']);
        self::assertFalse($status['maintenance']['overdue']);
        self::assertSame('success', $status['maintenance']['latest_run']['status']);
        self::assertFalse($status['maintenance']['latest_run']['dry_run']);
        self::assertTrue($status['metrics']['enabled']);

        try {
            (new SystemStatusService($this->pdo, $config))->forAdministrator($member);
            self::fail('Expected ordinary user to be denied system status.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->status);
        }

        $tracker->close($connectionId);
    }

    public function testFailedMaintenanceAttemptIsRecorded(): void
    {
        $config = $this->observabilityConfig();
        $otherConnection = Database::connect($config);
        $otherConnection->query("SELECT pg_advisory_lock(hashtext('chitchat-maintenance-cleanup'))");

        try {
            (new MaintenanceCoordinator($this->pdo, $config))->run(true);
            self::fail('Expected concurrent maintenance to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already running', $exception->getMessage());
        } finally {
            $otherConnection->query("SELECT pg_advisory_unlock(hashtext('chitchat-maintenance-cleanup'))");
        }

        $row = $this->pdo->query(
            'SELECT status, dry_run::int, error_message, duration_ms FROM maintenance_runs ORDER BY id DESC LIMIT 1',
        )->fetch();
        self::assertIsArray($row);
        self::assertSame('failure', $row['status']);
        self::assertSame(1, (int) $row['dry_run']);
        self::assertStringContainsString('already running', (string) $row['error_message']);
        self::assertGreaterThanOrEqual(0, (int) $row['duration_ms']);
    }

    private function observabilityConfig(): Config
    {
        $this->storagePath ??= sys_get_temp_dir() . '/chitchat-observability-' . bin2hex(random_bytes(8));
        if (!is_dir($this->storagePath)) {
            self::assertTrue(mkdir($this->storagePath, 0700, true));
        }

        return new Config(
            environment: $this->config->environment,
            debug: $this->config->debug,
            applicationName: $this->config->applicationName,
            applicationVersion: $this->config->applicationVersion,
            databaseHost: $this->config->databaseHost,
            databasePort: $this->config->databasePort,
            databaseName: $this->config->databaseName,
            databaseUser: $this->config->databaseUser,
            databasePassword: $this->config->databasePassword,
            databaseSslMode: $this->config->databaseSslMode,
            sessionName: $this->config->sessionName,
            sessionCookieSecure: $this->config->sessionCookieSecure,
            sessionCookieSameSite: $this->config->sessionCookieSameSite,
            loginMaxAttempts: $this->config->loginMaxAttempts,
            loginLockMinutes: $this->config->loginLockMinutes,
            presenceLeaseSeconds: $this->config->presenceLeaseSeconds,
            inactivityWarningSeconds: $this->config->inactivityWarningSeconds,
            attachmentStoragePath: $this->storagePath,
            attachmentMaxBytes: $this->config->attachmentMaxBytes,
            directMessageInspectionEnabled: $this->config->directMessageInspectionEnabled,
            directMessageInspectionRole: $this->config->directMessageInspectionRole,
            messageRevisionReviewEnabled: $this->config->messageRevisionReviewEnabled,
            messageRevisionReviewRole: $this->config->messageRevisionReviewRole,
            sseConnectionLeaseSeconds: 40,
            metricsBearerToken: 'test-metrics-token-with-enough-entropy',
            maintenanceMaxAgeHours: 26,
        );
    }

    private function activeSseConnections(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM sse_connections WHERE lease_expires_at > NOW()',
        )->fetchColumn();
    }
}
