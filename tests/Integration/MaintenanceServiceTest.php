<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Maintenance\MaintenanceService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;

final class MaintenanceServiceTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            $this->removePath($path);
        }
        parent::tearDown();
    }

    public function testDryRunAndCleanupApplyConfiguredRetention(): void
    {
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $auth = new AuthService($this->pdo, $config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $room = (new RoomService($this->pdo))->create(
            $root,
            'maintenance',
            'Maintenance',
            '',
            'public',
            0,
            0,
            '127.0.0.1',
        );
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.2');

        $messages = new MessageService($this->pdo);
        $oldMessage = $messages->send($member, $room->id, 'old room message');
        $newMessage = $messages->send($member, $room->id, 'new room message');
        $this->ageRow('room_messages', $oldMessage['id'], 'created_at', "NOW() - INTERVAL '2 days'");

        $direct = new DirectMessageService($this->pdo);
        $oldDirect = $direct->send($root, $member->id, 'old direct message');
        $newDirect = $direct->send($root, $member->id, 'new direct message');
        $this->ageRow('direct_messages', $oldDirect['id'], 'created_at', "NOW() - INTERVAL '2 days'");

        $attachments = new AttachmentService($this->pdo, $config);
        $oldAttachment = $attachments->upload(
            $member,
            $room->id,
            IncomingFile::forTesting('old.txt', $this->temporaryFile('old attachment')),
            '',
            '127.0.0.2',
        );
        $oldAttachmentPath = $this->attachmentPath($storage, (string) $this->pdo->query(
            'SELECT storage_key FROM attachments WHERE id = ' . (int) $oldAttachment['attachment']['id'],
        )->fetchColumn());
        $this->ageRow('room_messages', $oldAttachment['id'], 'created_at', "NOW() - INTERVAL '2 days'");

        $deletedAttachment = $attachments->upload(
            $member,
            $room->id,
            IncomingFile::forTesting('deleted.txt', $this->temporaryFile('deleted attachment')),
            '',
            '127.0.0.2',
        );
        $deletedAttachmentId = (int) $deletedAttachment['attachment']['id'];
        $deletedStorageKey = (string) $this->pdo->query(
            'SELECT storage_key FROM attachments WHERE id = ' . $deletedAttachmentId,
        )->fetchColumn();
        $deletedAttachmentPath = $this->attachmentPath($storage, $deletedStorageKey);
        $messages->delete($root, $deletedAttachment['id'], '127.0.0.1');
        $this->ageRow('attachments', $deletedAttachmentId, 'deleted_at', "NOW() - INTERVAL '2 days'");

        $orphanKey = str_repeat('ab', 32);
        $orphanPath = $this->attachmentPath($storage, $orphanKey);
        self::assertTrue(is_dir(dirname($orphanPath)) || mkdir(dirname($orphanPath), 0700, true));
        file_put_contents($orphanPath, 'orphan');
        touch($orphanPath, time() - 7200);

        $this->pdo->exec(<<<'SQL'
INSERT INTO audit_log (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
VALUES (NULL, 'old.audit', 'system', NULL, '{}'::jsonb, '127.0.0.1', NOW() - INTERVAL '2 days');
INSERT INTO login_attempts (username_canonical, ip_address, successful, reason, created_at)
VALUES ('old', '127.0.0.1', FALSE, 'old', NOW() - INTERVAL '2 days');
INSERT INTO request_rate_limits (scope, identifier_hash, window_started_at, attempt_count, updated_at)
VALUES ('old_scope', repeat('a', 64), NOW() - INTERVAL '3 days', 1, NOW() - INTERVAL '3 days');
INSERT INTO room_presence (
    connection_id, user_id, room_id, connected_at, last_seen_at, last_interaction_at, lease_expires_at
)
VALUES (
    '11111111-1111-4111-8111-111111111111', 1, 1, NOW() - INTERVAL '2 hours',
    NOW() - INTERVAL '2 hours', NOW() - INTERVAL '2 hours', NOW() - INTERVAL '1 hour'
);
SQL);
        (new EventRepository($this->pdo))->publish('global_broadcast', ['message' => 'old event']);
        $this->pdo->exec(
            "UPDATE realtime_events SET created_at = NOW() - INTERVAL '2 days' WHERE event_type = 'global_broadcast'",
        );
        $this->pdo->exec(<<<'SQL'
UPDATE system_settings
SET room_message_retention_days = 1,
    direct_message_retention_days = 1,
    audit_retention_days = 1,
    deleted_attachment_retention_days = 1,
    orphan_attachment_grace_hours = 1,
    realtime_event_retention_hours = 24,
    login_attempt_retention_days = 1
WHERE id = 1
SQL);

        $service = new MaintenanceService($this->pdo, $config);
        $dryRun = $service->run(true);
        self::assertTrue($dryRun['dry_run']);
        self::assertGreaterThanOrEqual(2, $dryRun['room_messages']);
        self::assertSame(1, $dryRun['direct_messages']);
        self::assertSame(1, $dryRun['deleted_attachments']);
        self::assertSame(1, $dryRun['orphan_files']);
        self::assertFileExists($oldAttachmentPath);
        self::assertFileExists($orphanPath);

        $result = $service->run(false);
        self::assertFalse($result['dry_run']);
        self::assertSame(1, $result['direct_messages']);
        self::assertGreaterThanOrEqual(2, $result['room_messages']);
        self::assertSame(1, $result['deleted_attachments']);
        self::assertSame(1, $result['orphan_files']);
        self::assertSame(0, $result['file_removal_failures']);
        self::assertFileDoesNotExist($oldAttachmentPath);
        self::assertFileDoesNotExist($deletedAttachmentPath);
        self::assertFileDoesNotExist($orphanPath);

        self::assertSame(0, $this->countById('direct_messages', $oldDirect['id']));
        self::assertSame(1, $this->countById('direct_messages', $newDirect['id']));
        self::assertSame(0, $this->countById('room_messages', $oldMessage['id']));
        self::assertSame(1, $this->countById('room_messages', $newMessage['id']));
        self::assertSame(0, $this->countById('attachments', $deletedAttachmentId));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM room_presence')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'old.audit'")->fetchColumn());
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'maintenance.cleanup'")->fetchColumn(),
        );
    }

    private function configWithStorage(string $path): Config
    {
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
            attachmentStoragePath: $path,
            attachmentMaxBytes: $this->config->attachmentMaxBytes,
            directMessageInspectionEnabled: $this->config->directMessageInspectionEnabled,
            directMessageInspectionRole: $this->config->directMessageInspectionRole,
        );
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/chitchat-maintenance-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0700, true));
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chitchat-maintenance-source-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function attachmentPath(string $storage, string $key): string
    {
        return $storage . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key;
    }

    private function ageRow(string $table, int $id, string $column, string $expression): void
    {
        $allowed = [
            'room_messages.created_at',
            'direct_messages.created_at',
            'attachments.deleted_at',
        ];
        self::assertContains($table . '.' . $column, $allowed);
        $this->pdo->exec(sprintf('UPDATE %s SET %s = %s WHERE id = %d', $table, $column, $expression, $id));
    }

    private function countById(string $table, int $id): int
    {
        self::assertContains($table, ['room_messages', 'direct_messages', 'attachments']);

        return (int) $this->pdo->query(
            sprintf('SELECT COUNT(*) FROM %s WHERE id = %d', $table, $id),
        )->fetchColumn();
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
