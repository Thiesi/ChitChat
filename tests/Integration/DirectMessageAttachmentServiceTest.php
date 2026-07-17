<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Upload\IncomingFile;

final class DirectMessageAttachmentServiceTest extends DatabaseTestCase
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

    public function testUploadCreatesMessageMetadataEventsAuditAndParticipantDownload(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Carol', 'different secure password', '127.0.0.3');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $service = new DirectMessageAttachmentService($this->pdo, $config);

        $message = $service->upload(
            actor: $alice,
            recipientUserId: $bob->id,
            file: IncomingFile::forTesting('../reports/result.txt', $this->temporaryFile("private result\n")),
            captionInput: 'Experiment result',
            ipAddress: '127.0.0.1',
        );

        self::assertSame('Experiment result', $message['body']);
        self::assertTrue($message['outgoing']);
        self::assertSame(['Experiment result'], array_column(
            (new DirectMessageService($this->pdo))->history($bob, $alice->id),
            'body',
        ));

        $metadata = $service->metadata($bob, [$message['id']]);
        self::assertCount(1, $metadata);
        self::assertSame('result.txt', $metadata[0]['name']);
        self::assertSame('text/plain', $metadata[0]['mime_type']);
        self::assertFalse($metadata[0]['previewable']);

        $download = $service->authorizeDownload($bob, $metadata[0]['id']);
        self::assertFileExists($download['path']);
        self::assertSame("private result\n", file_get_contents($download['path']));
        self::assertSame(hash('sha256', "private result\n"), $download['sha256']);

        try {
            $service->authorizeDownload($outsider, $metadata[0]['id']);
            self::fail('Expected participant-only attachment download authorization.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status);
            self::assertSame('attachment_not_found', $exception->errorCode);
        }
        self::assertSame([], $service->metadata($outsider, [$message['id']]));

        $aliceEvents = (new EventRepository($this->pdo))->visibleAfter($alice, 0);
        $bobEvents = (new EventRepository($this->pdo))->visibleAfter($bob, 0);
        $outsiderEvents = (new EventRepository($this->pdo))->visibleAfter($outsider, 0);
        self::assertSame(['direct_message'], array_column(array_map(
            static fn ($event): array => ['type' => $event->type],
            $aliceEvents,
        ), 'type'));
        self::assertSame(['direct_message'], array_column(array_map(
            static fn ($event): array => ['type' => $event->type],
            $bobEvents,
        ), 'type'));
        self::assertSame([], $outsiderEvents);
        self::assertSame(
            'direct_message.attachment_uploaded',
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
    }

    public function testBlockAndFilePolicyFailuresLeaveNoRowsOrStoredFiles(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $service = new DirectMessageAttachmentService($this->pdo, $config);
        (new DirectMessageBlockService($this->pdo))->block($bob, $alice->id);

        try {
            $service->upload(
                $alice,
                $bob->id,
                IncomingFile::forTesting('blocked.txt', $this->temporaryFile('blocked content')),
                '',
                '127.0.0.1',
            );
            self::fail('Expected blocked attachment rejection.');
        } catch (ApiException $exception) {
            self::assertSame('direct_message_unavailable', $exception->errorCode);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM direct_messages')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM direct_message_attachments')->fetchColumn());
        self::assertSame([], $this->storedFiles($storage));

        try {
            $service->upload(
                $bob,
                $alice->id,
                IncomingFile::forTesting('script.php', $this->temporaryFile('<?php echo 1;')),
                '',
                '127.0.0.2',
            );
            self::fail('Expected disallowed MIME rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_type_not_allowed', $exception->errorCode);
        }

        try {
            (new DirectMessageAttachmentService($this->pdo, $this->configWithStorage($storage, 1024)))->upload(
                $bob,
                $alice->id,
                IncomingFile::forTesting('large.txt', $this->temporaryFile(str_repeat('x', 2048))),
                '',
                '127.0.0.2',
            );
            self::fail('Expected size rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_too_large', $exception->errorCode);
        }
        self::assertSame([], $this->storedFiles($storage));
    }

    public function testDirectMessageRetentionRemovesAttachmentFileInSameRun(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $service = new DirectMessageAttachmentService($this->pdo, $config);
        $message = $service->upload(
            $alice,
            $bob->id,
            IncomingFile::forTesting('retained.txt', $this->temporaryFile('retention content')),
            '',
            '127.0.0.1',
        );
        $metadata = $service->metadata($alice, [$message['id']]);
        $path = $service->authorizeDownload($alice, $metadata[0]['id'])['path'];
        self::assertFileExists($path);

        $this->pdo->exec("UPDATE direct_messages SET created_at = NOW() - INTERVAL '2 days'");
        $this->pdo->exec('UPDATE system_settings SET direct_message_retention_days = 1 WHERE id = 1');

        $dryRun = (new CleanupService($this->pdo, $config))->run(true);
        self::assertSame(1, $dryRun['direct_messages']);
        self::assertSame(1, $dryRun['tracked_files']);
        self::assertFileExists($path);

        $result = (new CleanupService($this->pdo, $config))->run(false);
        self::assertSame(1, $result['direct_messages']);
        self::assertSame(1, $result['files_removed']);
        self::assertFileDoesNotExist($path);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM direct_message_attachments')->fetchColumn());
    }

    private function configWithStorage(string $path, int $maxBytes = 10_485_760): Config
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
            attachmentMaxBytes: $maxBytes,
            directMessageInspectionEnabled: $this->config->directMessageInspectionEnabled,
            directMessageInspectionRole: $this->config->directMessageInspectionRole,
        );
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/chitchat-dm-attachments-' . bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chitchat-dm-upload-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function storedFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
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
