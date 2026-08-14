<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Upload\AttachmentMetadataService;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;

final class AttachmentServiceTest extends DatabaseTestCase
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

    public function testUploadCreatesStoredMessageMetadataEventAndAudit(): void
    {
        [$root, $member, $room] = $this->roomWithMember('public');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $source = $this->temporaryFile("hello attachment\n");

        $message = (new AttachmentService($this->pdo, $config))->upload(
            actor: $member,
            roomId: $room->id,
            file: IncomingFile::forTesting('../reports/result.txt', $source),
            captionInput: 'Experiment result',
            ipAddress: '127.0.0.2',
        );

        self::assertSame('attachment', $message['type']);
        self::assertSame('Experiment result', $message['body']);
        self::assertSame('result.txt', $message['attachment']['name'] ?? null);
        self::assertSame('text/plain', $message['attachment']['mime_type'] ?? null);
        self::assertFalse($message['attachment']['previewable'] ?? true);

        $storedKey = $this->pdo->query('SELECT storage_key FROM attachments')->fetchColumn();
        self::assertIsString($storedKey);
        $storedPath = $storage
            . '/' . substr($storedKey, 0, 2)
            . '/' . substr($storedKey, 2, 2)
            . '/' . $storedKey;
        self::assertFileExists($storedPath);
        self::assertSame("hello attachment\n", file_get_contents($storedPath));

        $history = (new MessageService($this->pdo))->history($member, $room->id);
        self::assertSame($message['attachment'], $history[0]['attachment']);

        $events = (new EventRepository($this->pdo))->visibleAfter($member, 0);
        self::assertSame(['room_message'], array_map(
            static fn ($event): string => $event->type,
            $events,
        ));
        self::assertSame(
            'room.attachment_uploaded',
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );

        $download = (new AttachmentService($this->pdo, $config))->authorizeDownload(
            $root,
            (int) $message['attachment']['id'],
        );
        self::assertSame($storedPath, $download['path']);
        self::assertSame(hash('sha256', "hello attachment\n"), $download['sha256']);

        $metadata = (new AttachmentMetadataService($this->pdo))->forMessages(
            $member,
            $room->id,
            [$message['id']],
        );
        self::assertSame($message['attachment']['id'], $metadata[0]['id']);
    }

    public function testUploadSupportsReplyTargetAndResolvesMentionsInCaption(): void
    {
        [$root, $member, $room] = $this->roomWithMember('public');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);

        $original = (new MessageService($this->pdo))->send($root, $room->id, 'Original message for an attachment reply');

        $message = (new AttachmentService($this->pdo, $config))->upload(
            actor: $member,
            roomId: $room->id,
            file: IncomingFile::forTesting('evidence.txt', $this->temporaryFile("evidence\n")),
            captionInput: '@Root see attached',
            ipAddress: '127.0.0.2',
            replyToMessageId: $original['id'],
        );

        self::assertNotNull($message['reply_to']);
        self::assertSame($original['id'], $message['reply_to']['message_id']);
        self::assertTrue($message['reply_to']['available']);
        self::assertSame(
            [['user_id' => $root->id, 'username' => 'Root', 'broadcast' => false]],
            $message['mentions'],
        );

        $rooms = new RoomService($this->pdo);
        $otherRoom = $rooms->create($root, 'files-other', 'Other', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $otherRoom->id, '127.0.0.2');
        $this->expectException(ApiException::class);
        (new AttachmentService($this->pdo, $config))->upload(
            actor: $member,
            roomId: $otherRoom->id,
            file: IncomingFile::forTesting('evidence2.txt', $this->temporaryFile("evidence\n")),
            captionInput: '',
            ipAddress: '127.0.0.2',
            replyToMessageId: $original['id'],
        );
    }

    public function testPrivateAttachmentDownloadRequiresHistoryAuthorization(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $outsider = $auth->register('Outsider', 'another secure password', '127.0.0.2');
        $room = (new RoomService($this->pdo))->create(
            $root,
            'private-room',
            'Private Room',
            '',
            'private',
            0,
            0,
            '127.0.0.1',
        );
        $storage = $this->temporaryDirectory();
        $message = (new AttachmentService($this->pdo, $this->configWithStorage($storage)))->upload(
            $root,
            $room->id,
            IncomingFile::forTesting('private.txt', $this->temporaryFile('private')),
            '',
            '127.0.0.1',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Join the private room');
        (new AttachmentService($this->pdo, $this->configWithStorage($storage)))->authorizeDownload(
            $outsider,
            (int) $message['attachment']['id'],
        );
    }

    public function testDisallowedAndOversizedFilesAreRejectedBeforePersistence(): void
    {
        [, $member, $room] = $this->roomWithMember('public');
        $storage = $this->temporaryDirectory();
        $service = new AttachmentService($this->pdo, $this->configWithStorage($storage));

        try {
            $service->upload(
                $member,
                $room->id,
                IncomingFile::forTesting('script.php', $this->temporaryFile('<?php echo 1;')),
                '',
                '127.0.0.2',
            );
            self::fail('Expected disallowed MIME rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_type_not_allowed', $exception->errorCode);
        }

        try {
            (new AttachmentService($this->pdo, $this->configWithStorage($storage, 1024)))->upload(
                $member,
                $room->id,
                IncomingFile::forTesting('large.txt', $this->temporaryFile(str_repeat('x', 2048))),
                '',
                '127.0.0.2',
            );
            self::fail('Expected attachment size rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_too_large', $exception->errorCode);
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM room_messages WHERE message_type = 'attachment'")->fetchColumn());
    }

    public function testModeratorDeletionRevokesAccessWithoutDestroyingStoredEvidence(): void
    {
        [$root, $member, $room] = $this->roomWithMember('public');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $message = (new AttachmentService($this->pdo, $config))->upload(
            $member,
            $room->id,
            IncomingFile::forTesting('evidence.txt', $this->temporaryFile('evidence')),
            '',
            '127.0.0.2',
        );
        $download = (new AttachmentService($this->pdo, $config))->authorizeDownload(
            $root,
            (int) $message['attachment']['id'],
        );
        self::assertFileExists($download['path']);

        (new MessageService($this->pdo))->delete($root, $message['id'], '127.0.0.1');

        self::assertFileExists($download['path']);
        self::assertNotFalse($this->pdo->query('SELECT deleted_at FROM attachments')->fetchColumn());
        try {
            (new AttachmentService($this->pdo, $config))->authorizeDownload(
                $member,
                (int) $message['attachment']['id'],
            );
            self::fail('Expected deleted attachment access rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_deleted', $exception->errorCode);
        }

        $history = (new MessageService($this->pdo))->history($member, $room->id);
        self::assertTrue($history[0]['deleted']);
        self::assertNull($history[0]['attachment']);
    }

    /** @return array{0:\ChitChat\Auth\AuthenticatedUser, 1:\ChitChat\Auth\AuthenticatedUser, 2:\ChitChat\Room\Room} */
    private function roomWithMember(string $visibility): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create(
            $root,
            'files-' . $visibility,
            'Files',
            '',
            $visibility,
            0,
            0,
            '127.0.0.1',
        );
        $rooms->join($member, $room->id, '127.0.0.2');

        return [$root, $member, $room];
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
        );
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/chitchat-attachments-' . bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chitchat-upload-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->temporaryPaths[] = $path;

        return $path;
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
