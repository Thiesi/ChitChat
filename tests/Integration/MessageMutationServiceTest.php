<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\DirectMessage\DirectMessageAttachmentAccessService;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;

final class MessageMutationServiceTest extends DatabaseTestCase
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

    public function testRoomAuthorEditAndModeratorDeleteCreateImmutableRevisions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $other = $auth->register('Other', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'mutations', 'Mutations', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');
        $rooms->join($other, $room->id, '127.0.0.3');

        $messages = new MessageService($this->pdo);
        $message = $messages->send($author, $room->id, 'Original room message');
        $this->pdo->exec(
            'TRUNCATE moderation_reports, moderation_cases, account_notifications, realtime_events, audit_log RESTART IDENTITY',
        );
        $mutations = new RoomMessageMutationService($this->pdo);

        $edited = $mutations->edit($author, $message['id'], 'Edited room message', '127.0.0.2');
        self::assertSame('Edited room message', $edited['body']);
        self::assertNotNull($edited['edited_at']);
        self::assertTrue($edited['can_edit']);
        self::assertSame('Edited room message', $messages->history($author, $room->id)[0]['body']);

        try {
            $mutations->edit($other, $message['id'], 'Unauthorized edit', '127.0.0.3');
            self::fail('Expected non-author edit rejection.');
        } catch (ApiException $exception) {
            self::assertSame('message_author_required', $exception->errorCode);
        }

        $messages->delete($root, $message['id'], '127.0.0.1');
        $revisions = $this->pdo->query(<<<'SQL'
SELECT action, actor_user_id, body_before, body_after
FROM room_message_revisions
ORDER BY id
SQL)->fetchAll();
        self::assertCount(2, $revisions);
        self::assertSame(
            ['edit', $author->id, 'Original room message', 'Edited room message'],
            [$revisions[0]['action'], (int) $revisions[0]['actor_user_id'], $revisions[0]['body_before'], $revisions[0]['body_after']],
        );
        self::assertSame(
            ['delete', $root->id, 'Edited room message', null],
            [$revisions[1]['action'], (int) $revisions[1]['actor_user_id'], $revisions[1]['body_before'], $revisions[1]['body_after']],
        );
        self::assertSame(
            ['room.message_edited_by_author', 'room.message_deleted'],
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    public function testRoomAuthorDeletionRevokesAttachmentUntilConfiguredCleanup(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'room-files', 'Room Files', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $attachments = new AttachmentService($this->pdo, $config);
        $message = $attachments->upload(
            $author,
            $room->id,
            IncomingFile::forTesting('evidence.txt', $this->temporaryFile('room evidence')),
            'Room evidence caption',
            '127.0.0.2',
        );
        $attachmentId = (int) $message['attachment']['id'];
        $path = $attachments->authorizeDownload($author, $attachmentId)['path'];

        $deleted = (new RoomMessageMutationService($this->pdo))->deleteOwn(
            $author,
            $message['id'],
            '127.0.0.2',
        );
        self::assertTrue($deleted['deleted']);
        self::assertSame('author', $deleted['deletion_kind']);
        self::assertFileExists($path);
        try {
            $attachments->authorizeDownload($author, $attachmentId);
            self::fail('Expected deleted attachment rejection.');
        } catch (ApiException $exception) {
            self::assertSame('attachment_deleted', $exception->errorCode);
        }

        $this->pdo->exec("UPDATE attachments SET deleted_at = NOW() - INTERVAL '2 days'");
        $this->pdo->exec('UPDATE system_settings SET deleted_attachment_retention_days = 1 WHERE id = 1');
        $result = (new CleanupService($this->pdo, $config))->run(false);
        self::assertSame(1, $result['deleted_attachments']);
        self::assertSame(1, $result['files_removed']);
        self::assertFileDoesNotExist($path);
    }

    public function testDirectMessageEditRespectsBlocksAndDeletePublishesPerParticipant(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $messages = new DirectMessageService($this->pdo);
        $message = $messages->send($alice, $bob->id, 'Original private message');
        $this->pdo->exec(
            'TRUNCATE moderation_reports, moderation_cases, account_notifications, realtime_events, audit_log RESTART IDENTITY',
        );
        $mutations = new DirectMessageMutationService($this->pdo);

        $edited = $mutations->edit($alice, $message['id'], 'Edited private message', '127.0.0.1');
        self::assertSame('Edited private message', $edited['body']);
        self::assertSame('Edited private message', $messages->history($bob, $alice->id)[0]['body']);

        try {
            $mutations->edit($bob, $message['id'], 'Recipient edit', '127.0.0.2');
            self::fail('Expected recipient edit rejection.');
        } catch (ApiException $exception) {
            self::assertSame('message_author_required', $exception->errorCode);
        }

        (new DirectMessageBlockService($this->pdo))->block($bob, $alice->id);
        try {
            $mutations->edit($alice, $message['id'], 'Blocked edit', '127.0.0.1');
            self::fail('Expected blocked edit rejection.');
        } catch (ApiException $exception) {
            self::assertSame('direct_message_unavailable', $exception->errorCode);
        }

        $deleted = $mutations->deleteOwn($alice, $message['id'], '127.0.0.1');
        self::assertTrue($deleted['deleted']);
        self::assertNull($deleted['body']);
        self::assertSame('Message deleted.', $messages->history($bob, $alice->id)[0]['body']);

        $revisions = $this->pdo->query(<<<'SQL'
SELECT action, actor_user_id, body_before, body_after
FROM direct_message_revisions
ORDER BY id
SQL)->fetchAll();
        self::assertCount(2, $revisions);
        self::assertSame('Original private message', $revisions[0]['body_before']);
        self::assertSame('Edited private message', $revisions[0]['body_after']);
        self::assertSame('Edited private message', $revisions[1]['body_before']);
        self::assertNull($revisions[1]['body_after']);

        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM realtime_events')->fetchColumn());
        self::assertCount(2, (new EventRepository($this->pdo))->visibleAfter($alice, 0));
        self::assertCount(2, (new EventRepository($this->pdo))->visibleAfter($bob, 0));
    }

    public function testDeletedDirectAttachmentIsRevokedThenRemoved(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $storage = $this->temporaryDirectory();
        $config = $this->configWithStorage($storage);
        $attachments = new DirectMessageAttachmentService($this->pdo, $config);
        $message = $attachments->upload(
            $alice,
            $bob->id,
            IncomingFile::forTesting('private.txt', $this->temporaryFile('private evidence')),
            'Private evidence caption',
            '127.0.0.1',
        );
        $access = new DirectMessageAttachmentAccessService($this->pdo, $config);
        $metadata = $access->metadata($alice, [$message['id']]);
        self::assertCount(1, $metadata);
        $attachmentId = $metadata[0]['id'];
        $path = $access->authorizeDownload($bob, $attachmentId)['path'];

        (new DirectMessageMutationService($this->pdo))->deleteOwn($alice, $message['id'], '127.0.0.1');
        self::assertSame([], $access->metadata($bob, [$message['id']]));
        self::assertFileExists($path);
        try {
            $access->authorizeDownload($bob, $attachmentId);
            self::fail('Expected deleted direct attachment rejection.');
        } catch (ApiException $exception) {
            self::assertSame(410, $exception->status);
            self::assertSame('attachment_deleted', $exception->errorCode);
        }

        $this->pdo->exec("UPDATE direct_message_attachments SET deleted_at = NOW() - INTERVAL '2 days'");
        $this->pdo->exec('UPDATE system_settings SET deleted_attachment_retention_days = 1 WHERE id = 1');
        $dryRun = (new CleanupService($this->pdo, $config))->run(true);
        self::assertSame(1, $dryRun['deleted_attachments']);
        self::assertSame(1, $dryRun['tracked_files']);

        $result = (new CleanupService($this->pdo, $config))->run(false);
        self::assertSame(1, $result['deleted_attachments']);
        self::assertSame(1, $result['files_removed']);
        self::assertFileDoesNotExist($path);
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
        $path = sys_get_temp_dir() . '/chitchat-mutations-' . bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chitchat-mutation-upload-');
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
