<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Backup\BackupRunRecorder;
use ChitChat\Database;
use ChitChat\Observability\SystemStatusService;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Push queue health and backup freshness on System status (#310). */
final class PushBackupHealthTest extends DatabaseTestCase
{
    private const PUSH = ['webPushVapidPublicKey' => 'public-test-key', 'webPushVapidPrivateKey' => 'private-test-key', 'webPushVapidSubject' => 'mailto:admin@example.test'];

    public function testPushNotConfiguredIsNeutralWhateverIsQueued(): void
    {
        $this->delivery('pending', '-20 minutes', '-20 minutes');

        $push = $this->systemStatus($this->configWith(['webPushVapidPublicKey' => '', 'webPushVapidPrivateKey' => '']))['push'];
        self::assertFalse($push['configured']);
        self::assertSame('not_configured', $push['status']);
        self::assertFalse($push['overdue']);
    }

    public function testOnlyADeliveryThatIsAlreadyDueMakesPushOverdue(): void
    {
        // Created long ago but waiting for its retry: pending, not due, not overdue.
        $this->delivery('pending', '-2 hours', '+10 minutes');
        $push = $this->systemStatus()['push'];
        self::assertSame('ok', $push['status']);
        self::assertSame(1, $push['pending_deliveries']);
        self::assertSame(0, $push['due_deliveries']);
        self::assertGreaterThanOrEqual(7200, $push['oldest_pending_seconds']);
        self::assertNull($push['oldest_due_seconds']);
        self::assertSame(300, $push['overdue_after_seconds']);

        // Due for four minutes: within the threshold.
        $this->delivery('pending', '-4 minutes', '-4 minutes');
        $push = $this->systemStatus()['push'];
        self::assertFalse($push['overdue']);
        self::assertGreaterThanOrEqual(240, $push['oldest_due_seconds']);
        self::assertLessThan(300, $push['oldest_due_seconds']);

        // Due for six minutes: neither the cron sweep nor the listener is working.
        $this->delivery('pending', '-6 minutes', '-6 minutes');
        $push = $this->systemStatus()['push'];
        self::assertTrue($push['overdue']);
        self::assertSame('overdue', $push['status']);
        self::assertSame(2, $push['due_deliveries']);
    }

    public function testLastSentAndFailedDeliveries(): void
    {
        $push = $this->systemStatus()['push'];
        self::assertNull($push['last_sent_at']);
        $this->delivery('sent', '-1 hour', '-1 hour', '-30 minutes');
        $this->delivery('failed', '-1 hour', '-1 hour', '-20 minutes');

        $push = $this->systemStatus()['push'];
        self::assertNotNull($push['last_sent_at']);
        self::assertEqualsWithDelta((new DateTimeImmutable('-30 minutes'))->getTimestamp(), (new DateTimeImmutable($push['last_sent_at']))->getTimestamp(), 5);
        self::assertSame(1, $push['failed_deliveries']);
        self::assertSame(0, $push['pending_deliveries']);
    }

    public function testBackupStatusFromRecordedRuns(): void
    {
        $backups = $this->systemStatus()['backups'];
        self::assertTrue($backups['tracking_enabled']);
        self::assertSame(26, $backups['maximum_age_hours']);
        self::assertSame('unknown', $backups['status'], 'Nothing recorded: never "ok".');
        self::assertTrue($backups['overdue'], 'Unknown counts as overdue while tracking is on.');
        self::assertNull($backups['latest_run']);

        $recorder = $this->recorder($warnings);
        self::assertTrue($recorder->record(new DateTimeImmutable('-2 hours -5 minutes'), new DateTimeImmutable('-2 hours'), 'success', 'live', 4096, '/srv/backups/20261113T024500Z-abcd1234', null, '3.9.0'));
        $backups = $this->systemStatus()['backups'];
        self::assertSame('ok', $backups['status']);
        self::assertFalse($backups['overdue']);
        self::assertSame('20261113T024500Z-abcd1234', $backups['latest_run']['set_name'], 'The basename only, never the path.');
        self::assertSame(4096, $backups['latest_success']['size_bytes']);
        self::assertSame('live', $backups['latest_success']['consistency']);
        self::assertGreaterThanOrEqual(7200, $backups['latest_success_age_seconds']);

        $recorder->record(new DateTimeImmutable('-1 hour'), new DateTimeImmutable('-1 hour'), 'failure', 'live', null, null, 'backup_failed', '3.9.0');
        $backups = $this->systemStatus()['backups'];
        self::assertSame('failed', $backups['status']);
        self::assertSame('backup_failed', $backups['latest_run']['error_kind']);
        self::assertSame('success', $backups['latest_success']['outcome']);
        self::assertFalse($backups['overdue'], 'The last success is still within the maximum age.');
        self::assertSame([], $warnings);
    }

    public function testAnOldSuccessIsOverdueAndZeroTurnsTrackingOff(): void
    {
        $this->recorder($warnings)->record(new DateTimeImmutable('-30 hours'), new DateTimeImmutable('-30 hours'), 'success', 'offline', 1, 'set', null, '3.9.0');
        $backups = $this->systemStatus()['backups'];
        self::assertSame('overdue', $backups['status']);
        self::assertTrue($backups['overdue']);

        $this->pdo->exec('UPDATE system_settings SET backup_max_age_hours = 0 WHERE id = 1');
        $backups = $this->systemStatus()['backups'];
        self::assertSame('not_tracked', $backups['status']);
        self::assertFalse($backups['tracking_enabled']);
        self::assertFalse($backups['overdue']);
    }

    public function testTheRecorderKeepsTheNewestHundredRuns(): void
    {
        $recorder = $this->recorder($warnings);
        for ($i = 0; $i < BackupRunRecorder::KEEP + 5; $i++) {
            $recorder->record(new DateTimeImmutable("-{$i} minutes"), new DateTimeImmutable("-{$i} minutes"), 'success', 'live', $i, "set-{$i}", null, '3.9.0');
        }
        self::assertSame(BackupRunRecorder::KEEP, (int) $this->pdo->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn());
        self::assertSame('set-5', $this->pdo->query('SELECT set_name FROM backup_runs ORDER BY id ASC LIMIT 1')->fetchColumn(), 'The oldest rows went.');
    }

    public function testARunThatCantBeRecordedOnlyWarns(): void
    {
        // A database that can't be written (read-only, or a backup role without INSERT).
        $warnings = [];
        $readOnly = new BackupRunRecorder(
            function (): PDO {
                $pdo = Database::connect($this->config);
                $pdo->exec('SET default_transaction_read_only = on');

                return $pdo;
            },
            static function (string $line) use (&$warnings): void {
                $warnings[] = $line;
            },
        );
        self::assertFalse($readOnly->record(new DateTimeImmutable(), new DateTimeImmutable(), 'success', 'live', 1, 'set', null, '3.9.0'));
        self::assertCount(1, $warnings);
        self::assertStringStartsWith('Warning: backup run not recorded (PDOException).', $warnings[0]);

        // No database at all, and even a warning sink that fails, never reach the backup.
        $unreachable = new BackupRunRecorder(
            static fn (): PDO => throw new RuntimeException('connection refused'),
            static fn (string $line) => throw new RuntimeException('stderr closed'),
        );
        self::assertFalse($unreachable->record(new DateTimeImmutable(), new DateTimeImmutable(), 'failure', 'live', null, null, 'unexpected', '3.9.0'));

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn());
        self::assertSame('unknown', $this->systemStatus()['backups']['status']);
    }

    public function testTheMaximumAgeIsAnOperationalSetting(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'Backup-Health-Test-Aa1!', '127.0.0.1');
        $service = new \ChitChat\Admin\SystemSettingsService($this->pdo);
        $current = $service->get($root);
        self::assertSame(26, $current['backup_max_age_hours']);

        $update = fn (?int $hours): array => $service->update(
            $root, true, false, 0, 0, 0, 30, 24, 168, 30, '127.0.0.1', backupMaxAgeHours: $hours,
        );
        self::assertSame(48, $update(48)['backup_max_age_hours']);
        self::assertSame(48, $update(null)['backup_max_age_hours'], 'Left unchanged when omitted.');
        self::assertSame(0, $update(0)['backup_max_age_hours']);
        foreach ([-1, 721] as $invalid) {
            try {
                $update($invalid);
                self::fail("{$invalid} was accepted.");
            } catch (\ChitChat\Http\ApiException $exception) {
                self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
            }
        }
    }

    /** @param ?list<string> $warnings */
    private function recorder(?array &$warnings): BackupRunRecorder
    {
        $warnings = [];

        return new BackupRunRecorder(
            fn (): PDO => Database::connect($this->config),
            static function (string $line) use (&$warnings): void {
                $warnings[] = $line;
            },
        );
    }

    /** @return array<string, mixed> */
    private function systemStatus(?\ChitChat\Config $config = null): array
    {
        return (new SystemStatusService($this->pdo, $config ?? $this->configWith(self::PUSH)))->snapshot();
    }

    private int $deliveries = 0;

    private function delivery(string $state, string $created, string $nextAttempt, ?string $completed = null): void
    {
        $user = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($user === 0) {
            $user = (new AuthService($this->pdo, $this->config))->register('Pushed', 'Backup-Health-Test-Aa1!', '127.0.0.1')->id;
        }
        $this->deliveries++;
        $at = static fn (string $relative): string => (new DateTimeImmutable($relative))->format('Y-m-d H:i:s.uP');
        $job = $this->pdo->prepare(<<<'SQL'
INSERT INTO push_dispatch_jobs (user_id, event_key, expires_at, created_at, finished_at, outcome)
VALUES (:user_id, :event_key, NOW() + INTERVAL '1 day', :created_at, :finished_at, :outcome)
RETURNING id
SQL);
        $finished = $state === 'pending' ? null : $at($completed ?? 'now');
        $job->execute([
            'user_id' => $user, 'event_key' => 'health-' . $this->deliveries, 'created_at' => $at($created),
            'finished_at' => $finished, 'outcome' => $finished === null ? null : $state,
        ]);
        $jobId = (int) $job->fetchColumn();
        $delivery = $this->pdo->prepare(<<<'SQL'
INSERT INTO push_deliveries (job_id, subscription_fingerprint, state, next_attempt_at, completed_at, outcome)
VALUES (:job_id, :fingerprint, :state, :next_attempt_at, :completed_at, :outcome)
SQL);
        $delivery->execute([
            'job_id' => $jobId, 'fingerprint' => str_repeat('a', 64), 'state' => $state, 'next_attempt_at' => $at($nextAttempt),
            'completed_at' => $finished, 'outcome' => $finished === null ? null : $state,
        ]);
    }
}
