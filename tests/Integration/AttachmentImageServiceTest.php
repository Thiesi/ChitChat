<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\PersonalDataExportService;
use ChitChat\Auth\AuthService;
use ChitChat\Backup\AttachmentInventory;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentAccessService;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\Http\ApiException;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;
use ChitChat\Upload\AttachmentFileStore;
use ChitChat\Upload\AttachmentImageBackfill;
use ChitChat\Upload\AttachmentImageMetadata;
use ChitChat\Upload\AttachmentMetadataService;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;
use PDOException;
use RuntimeException;

final class AttachmentImageServiceTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD with WebP is unavailable.');
        }
        $this->storage = $this->temporaryDirectory(true);
        $this->config = $this->configWith(['attachmentStoragePath' => $this->storage]);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();
        parent::tearDown();
    }

    public function testRoomUploadHistoryMetadataEventsAndExportAgreeAndKeepStorageKeysPrivate(): void
    {
        [$root, $member, $room] = $this->people();
        $bytes = TestImages::jpeg(6);
        $message = (new AttachmentService($this->pdo, $this->config))->upload(
            $member, $room->id, IncomingFile::forTesting('portrait.jpg', $this->temporaryFile($bytes)), '', '127.0.0.2');
        $attachment = $message['attachment'];
        self::assertNotNull($attachment);
        self::assertSame([60, 100], [$attachment['width'], $attachment['height']]);
        self::assertSame([60, 100], [$attachment['preview']['width'], $attachment['preview']['height']]);
        self::assertSame('image/webp', $attachment['preview']['mime_type']);
        $history = (new MessageService($this->pdo))->history($root, $room->id);
        self::assertSame($attachment, $history[0]['attachment']);
        $metadata = (new AttachmentMetadataService($this->pdo))->forMessages($root, $room->id, [$message['id']])[0];
        unset($metadata['message_id']);
        self::assertSame($attachment, $metadata);
        $events = self::withoutRoomListSignals((new EventRepository($this->pdo))->visibleAfter($root, 0));
        self::assertSame($this->canonical($attachment), $this->canonical($events[0]->payload['message']['attachment']));
        $service = new AttachmentService($this->pdo, $this->config);
        $original = $service->authorizeDownload($root, $attachment['id']);
        self::assertSame($bytes, file_get_contents($original['path']));
        $preview = $service->authorizeDownload($root, $attachment['id'], 'preview');
        $rendered = file_get_contents($preview['path']);
        self::assertIsString($rendered);
        self::assertSame($attachment['preview']['sha256'], hash('sha256', $rendered));
        self::assertSame($attachment['preview']['size_bytes'], strlen($rendered));
        self::assertStringNotContainsString('private GPS/camera metadata', $rendered);
        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($member, '127.0.0.2');
        $exported = $export['rooms']['authored_messages'][0]['attachment'];
        self::assertSame(60, $exported['width']);
        self::assertSame($attachment['preview'], $exported['preview']);
        $json = json_encode([$message, $metadata, $export], JSON_THROW_ON_ERROR);
        foreach ($this->keys() as $key) {
            self::assertStringNotContainsString($key, $json);
        }
        self::assertSame(2, AttachmentInventory::scan($this->storage)->fileCount);
    }

    public function testDirectImagesHaveTheSameMetadataAndParticipantOnlyVariant(): void
    {
        [$root, $member] = $this->people();
        $outsider = (new AuthService($this->pdo, $this->config))->register('Other', 'a third safe password', '127.0.0.3');
        $service = new DirectMessageAttachmentService($this->pdo, $this->config);
        $bytes = TestImages::png(2048, 1024);
        $message = $service->upload($root, $member->id,
            IncomingFile::forTesting('wide.png', $this->temporaryFile($bytes)), '', '127.0.0.1');
        $metadata = $service->metadata($member, [$message['id']])[0];
        self::assertSame([2048, 1024], [$metadata['width'], $metadata['height']]);
        self::assertSame([1024, 512], [$metadata['preview']['width'], $metadata['preview']['height']]);
        $access = new DirectMessageAttachmentAccessService($this->pdo, $this->config);
        self::assertSame([$metadata], $access->metadata($member, [$message['id']]));
        self::assertSame($bytes, file_get_contents($access->authorizeDownload($member, $metadata['id'])['path']));
        self::assertSame('image/webp', $access->authorizeDownload($member, $metadata['id'], 'preview')['mime_type']);
        $this->error('attachment_not_found', fn () => $access->authorizeDownload($outsider, $metadata['id'], 'preview'), 404);
        self::assertSame([], $access->metadata($outsider, [$message['id']]));
        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($member, '127.0.0.2');
        self::assertSame($metadata['preview'], $export['direct_messages']['messages'][0]['attachment']['preview']);
    }

    public function testOverBudgetAndNonImagesKeepOriginalDownloadsAndNullablePreview(): void
    {
        [$root, , $room] = $this->people();
        $service = new AttachmentService($this->pdo, $this->config);
        foreach ([TestImages::pngHeader(6001, 2000), TestImages::pngHeader(4001, 4000), 'plain text'] as $index => $bytes) {
            $message = $service->upload($root, $room->id,
                IncomingFile::forTesting('file-' . $index, $this->temporaryFile($bytes)), '', '127.0.0.1');
            $attachment = $message['attachment'];
            self::assertNotNull($attachment);
            self::assertNull($attachment['preview']);
            if ($index === 0) {
                self::assertSame([6001, 2000], [$attachment['width'], $attachment['height']]);
            } elseif ($index === 1) {
                self::assertSame([4001, 4000], [$attachment['width'], $attachment['height']]);
            } else {
                self::assertNull($attachment['width']);
                self::assertNull($attachment['height']);
            }
            self::assertSame($bytes, file_get_contents($service->authorizeDownload($root, $attachment['id'])['path']));
            $this->error('attachment_preview_unavailable', fn () => $service->authorizeDownload($root, $attachment['id'], 'preview'), 404);
            $this->error('validation_error', fn () => $service->authorizeDownload($root, $attachment['id'], 'huge'), 400);
        }
        self::assertSame(3, AttachmentInventory::scan($this->storage)->fileCount);
    }

    public function testLegacyBackfillIsBoundedDryRunAndDoesNotTouchDeletedImages(): void
    {
        [$root, , $room] = $this->people();
        $first = $this->roomImage($root, $room->id);
        $second = $this->roomImage($root, $room->id);
        $third = $this->roomImage($root, $room->id);
        foreach ([$first, $second, $third] as $id) {
            $this->makeLegacy('attachments', $id);
        }
        $this->pdo->exec("UPDATE attachments SET deleted_at = NOW() WHERE id = $third");
        $service = new AttachmentService($this->pdo, $this->config);
        $this->error('attachment_preview_unavailable', fn () => $service->authorizeDownload($root, $first, 'preview'), 404);
        $before = AttachmentInventory::scan($this->storage)->toArray();
        $backfill = new AttachmentImageBackfill($this->pdo, $this->config);
        $dry = $backfill->run('room', 1, 0, true);
        self::assertSame(1, $dry['candidates']);
        self::assertSame(0, $dry['updated']);
        self::assertSame($before, AttachmentInventory::scan($this->storage)->toArray());
        self::assertNull($this->pdo->query("SELECT image_width FROM attachments WHERE id = $first")->fetchColumn());
        $actual = $backfill->run('room', 1, 0, false);
        self::assertSame(1, $actual['updated']);
        self::assertSame(1, $actual['previews_generated']);
        self::assertSame($first, $actual['next_id']);
        self::assertSame('image/webp', $service->authorizeDownload($root, $first, 'preview')['mime_type']);
        self::assertNull($this->pdo->query("SELECT image_width FROM attachments WHERE id = $second")->fetchColumn());
        $next = $backfill->run('room', 1, $actual['next_id'], false);
        self::assertSame($second, $next['next_id']);
        self::assertSame(0, $backfill->run('room', 100, $second, false)['candidates']);
        self::assertNull($this->pdo->query("SELECT preview_storage_key FROM attachments WHERE id = $third")->fetchColumn());
        $this->error('attachment_deleted', fn () => $service->authorizeDownload($root, $third, 'preview'), 410);
    }

    public function testMaintenanceProtectsPreviewOrphansAndDeletesBothFilesButCountsAttachments(): void
    {
        [$root, $member, $room] = $this->people();
        $this->roomImage($root, $room->id);
        $this->directImage($root, $member->id);
        $keys = $this->keys();
        self::assertCount(4, $keys);
        $files = new AttachmentFileStore($this->config);
        foreach ($keys as $key) {
            touch($files->pathForKey($key), time() - 3 * 86400);
        }
        $cleanup = new CleanupService($this->pdo, $this->config);
        self::assertSame(0, $cleanup->run(false)['orphan_files']);
        self::assertSame(4, AttachmentInventory::scan($this->storage)->fileCount);
        $this->pdo->exec("UPDATE attachments SET deleted_at = NOW() - INTERVAL '40 days'");
        $this->pdo->exec("UPDATE direct_message_attachments SET deleted_at = NOW() - INTERVAL '40 days'");
        $dry = $cleanup->run(true);
        self::assertSame(2, $dry['deleted_attachments']);
        self::assertSame(4, $dry['tracked_files']);
        self::assertSame(4, AttachmentInventory::scan($this->storage)->fileCount);
        $actual = $cleanup->run(false);
        self::assertSame(2, $actual['deleted_attachments']);
        self::assertSame(4, $actual['files_removed']);
        self::assertSame(0, AttachmentInventory::scan($this->storage)->fileCount);
        foreach ($keys as $key) {
            self::assertFileDoesNotExist($files->pathForKey($key));
        }
    }

    public function testMessageRetentionAndDeletedRoomPurgeRemoveRenditions(): void
    {
        [$root, $member, $room] = $this->people();
        $this->roomImage($root, $room->id);
        $this->directImage($root, $member->id);
        $this->pdo->exec("UPDATE room_messages SET created_at = NOW() - INTERVAL '3 days'");
        $this->pdo->exec("UPDATE direct_messages SET created_at = NOW() - INTERVAL '3 days'");
        $this->pdo->exec('UPDATE system_settings SET room_message_retention_days = 1, direct_message_retention_days = 1 WHERE id = 1');
        $result = (new CleanupService($this->pdo, $this->config))->run(false);
        self::assertSame(4, $result['files_removed']);
        self::assertSame(0, AttachmentInventory::scan($this->storage)->fileCount);
        $this->pdo->exec('UPDATE system_settings SET room_message_retention_days = 0, direct_message_retention_days = 0 WHERE id = 1');
        $this->roomImage($root, $room->id);
        $this->pdo->exec("UPDATE rooms SET deleted_at = NOW() - INTERVAL '40 days' WHERE id = {$room->id}");
        $result = (new CleanupService($this->pdo, $this->config))->run(false);
        self::assertSame(1, $result['purged_rooms']);
        self::assertSame(2, $result['files_removed']);
        self::assertSame(0, AttachmentInventory::scan($this->storage)->fileCount);
    }

    public function testFailedUploadAndBackfillWritesRemoveOnlyTheirNewFiles(): void
    {
        [$root, $member, $room] = $this->people();
        foreach (['attachments', 'direct_message_attachments'] as $table) {
            $this->pdo->exec("CREATE FUNCTION test_fail_image_write() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''deliberate image write failure''; END'; CREATE TRIGGER test_fail_image_write BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION test_fail_image_write()");
            try {
                try {
                    if ($table === 'attachments') {
                        $this->roomImage($root, $room->id);
                    } else {
                        $this->directImage($root, $member->id);
                    }
                    self::fail('Expected attachment insert failure.');
                } catch (PDOException $exception) {
                    self::assertStringContainsString('deliberate image write failure', $exception->getMessage());
                }
                self::assertSame(0, AttachmentInventory::scan($this->storage)->fileCount);
            } finally {
                $this->pdo->exec("DROP TRIGGER test_fail_image_write ON $table; DROP FUNCTION test_fail_image_write()");
            }
        }
        $id = $this->roomImage($root, $room->id);
        $this->makeLegacy('attachments', $id);
        self::assertSame(1, AttachmentInventory::scan($this->storage)->fileCount);
        $this->pdo->exec("CREATE FUNCTION test_fail_image_write() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''deliberate image write failure''; END'; CREATE TRIGGER test_fail_image_write BEFORE UPDATE OF image_width ON attachments FOR EACH ROW EXECUTE FUNCTION test_fail_image_write()");
        try {
            try {
                (new AttachmentImageBackfill($this->pdo, $this->config))->run('room', 1, 0, false);
                self::fail('Expected image metadata failure.');
            } catch (PDOException $exception) {
                self::assertStringContainsString('deliberate image write failure', $exception->getMessage());
            }
            self::assertSame(1, AttachmentInventory::scan($this->storage)->fileCount);
            self::assertNull($this->pdo->query("SELECT image_width FROM attachments WHERE id = $id")->fetchColumn());
        } finally {
            $this->pdo->exec('DROP TRIGGER test_fail_image_write ON attachments; DROP FUNCTION test_fail_image_write()');
        }
    }

    public function testBackfillSharesCleanupLockAndDatabaseRejectsPartialMetadata(): void
    {
        [$root, $member, $room] = $this->people();
        $id = $this->roomImage($root, $room->id);
        $this->makeLegacy('attachments', $id);
        $direct = $this->directImage($root, $member->id);
        $this->makeLegacy('direct_message_attachments', $direct);
        $other = Database::connect($this->config);
        $other->query("SELECT pg_advisory_lock(hashtext('chitchat-maintenance-cleanup'))");
        try {
            try {
                (new AttachmentImageBackfill($this->pdo, $this->config))->run('room', 1, 0, false);
                self::fail('Expected shared maintenance lock to exclude backfill.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('already running', $exception->getMessage());
            }
        } finally {
            $other->query("SELECT pg_advisory_unlock(hashtext('chitchat-maintenance-cleanup'))");
        }
        self::assertSame(1, (new AttachmentImageBackfill($this->pdo, $this->config))->run('direct', 1, 0, false)['previews_generated']);
        foreach (["image_width = 5", "preview_storage_key = '" . str_repeat('a', 64) . "'"] as $set) {
            try {
                $this->pdo->exec("UPDATE attachments SET $set WHERE id = $id");
                self::fail('Expected partial metadata to violate its database invariant.');
            } catch (PDOException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
        }
    }

    public function testCliDryRunAndLiveBatchesStayBoundedAndRejectUnboundedArguments(): void
    {
        [$root, $member, $room] = $this->people();
        $first = $this->roomImage($root, $room->id);
        $second = $this->roomImage($root, $room->id);
        $direct = $this->directImage($root, $member->id);
        $this->makeLegacy('attachments', $first);
        $this->makeLegacy('attachments', $second);
        $this->makeLegacy('direct_message_attachments', $direct);
        [$status, $output] = $this->cli(['--kind=room', '--limit=1', '--after-id=0', '--dry-run']);
        self::assertSame(0, $status);
        self::assertSame(1, $output['candidates']);
        self::assertSame(0, $output['updated']);
        self::assertSame(3, AttachmentInventory::scan($this->storage)->fileCount);
        [$status, $output] = $this->cli(['--kind=room', '--limit=1', '--after-id=0']);
        self::assertSame(0, $status);
        self::assertSame($first, $output['next_id']);
        self::assertSame(1, $output['previews_generated']);
        self::assertSame(4, AttachmentInventory::scan($this->storage)->fileCount);
        self::assertNull($this->pdo->query("SELECT preview_storage_key FROM attachments WHERE id = $second")->fetchColumn());
        foreach ([['--limit=101'], ['--all'], ['--after-id=-1']] as $arguments) {
            [$status] = $this->cli($arguments, false);
            self::assertSame(2, $status);
        }
        [$status, $output] = $this->cli(['--kind=direct', '--limit=1']);
        self::assertSame(0, $status);
        self::assertSame(1, $output['updated']);
        self::assertSame(1, $output['previews_generated']);
        self::assertSame(5, AttachmentInventory::scan($this->storage)->fileCount);
    }

    public function testConcurrentDeleteIsRecheckedAfterBackfillWaitsForTheAttachmentLock(): void
    {
        [$root, , $room] = $this->people();
        $id = $this->roomImage($root, $room->id);
        $this->makeLegacy('attachments', $id);
        $other = Database::connect($this->config);
        $other->beginTransaction();
        $other->exec("UPDATE attachments SET deleted_at = NOW() WHERE id = $id");
        $stdout = $this->temporaryFile();
        $stderr = $this->temporaryFile();
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['ATTACHMENT_STORAGE_PATH'] = $this->storage;
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/backfill-attachment-images',
            '--kind=room', '--limit=1'], [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $closed = false;
        try {
            $deadline = hrtime(true) + 10_000_000_000;
            $waiting = false;
            do {
                $waiting = (int) $this->pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND query LIKE '%FOR UPDATE OF a%'")->fetchColumn() > 0;
                if ($waiting || !proc_get_status($process)['running']) {
                    break;
                }
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue($waiting, 'Backfill did not reach the locked attachment: ' . (string) file_get_contents($stderr));
            $other->commit();
            $status = proc_close($process);
            $closed = true;
            self::assertSame(0, $status, (string) file_get_contents($stderr));
            $result = json_decode((string) file_get_contents($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $result['candidates']);
            self::assertSame(0, $result['updated']);
            self::assertSame(0, $result['previews_generated']);
            self::assertNull($this->pdo->query("SELECT preview_storage_key FROM attachments WHERE id = $id")->fetchColumn());
            self::assertSame(1, AttachmentInventory::scan($this->storage)->fileCount);
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
            if (!$closed) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int,array<string,mixed>}
     */
    private function cli(array $arguments, bool $json = true): array
    {
        $stdout = $this->temporaryFile();
        $stderr = $this->temporaryFile();
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['ATTACHMENT_STORAGE_PATH'] = $this->storage;
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/backfill-attachment-images', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes,
            dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $status = proc_close($process);
        $body = file_get_contents($stdout);
        self::assertIsString($body);
        if ($json) {
            self::assertSame('', file_get_contents($stderr));
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
        } else {
            self::assertStringContainsString('Usage:', (string) file_get_contents($stderr));
            $decoded = [];
        }
        return [$status, $decoded];
    }
    /** @return array{\ChitChat\Auth\AuthenticatedUser,\ChitChat\Auth\AuthenticatedUser,\ChitChat\Room\Room} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'image-files', 'Images', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        return [$root, $member, $room];
    }

    private function roomImage(\ChitChat\Auth\AuthenticatedUser $user, int $roomId): int
    {
        $message = (new AttachmentService($this->pdo, $this->config))->upload($user, $roomId,
            IncomingFile::forTesting('sample.png', $this->temporaryFile(TestImages::png(20, 10))), '', '127.0.0.1');
        return (int) $message['attachment']['id'];
    }

    private function directImage(\ChitChat\Auth\AuthenticatedUser $user, int $recipient): int
    {
        $service = new DirectMessageAttachmentService($this->pdo, $this->config);
        $message = $service->upload($user, $recipient,
            IncomingFile::forTesting('sample.png', $this->temporaryFile(TestImages::png(20, 10))), '', '127.0.0.1');
        return $service->metadata($user, [$message['id']])[0]['id'];
    }

    private function makeLegacy(string $table, int $id): void
    {
        self::assertContains($table, ['attachments', 'direct_message_attachments']);
        $key = $this->pdo->query("SELECT preview_storage_key FROM $table WHERE id = $id")->fetchColumn();
        self::assertIsString($key);
        (new AttachmentFileStore($this->config))->remove($key);
        $sets = array_map(static fn (string $column): string => $column . ' = NULL', AttachmentImageMetadata::COLUMNS);
        $this->pdo->exec('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . " WHERE id = $id");
    }

    /** @return list<string> */
    private function keys(): array
    {
        return $this->pdo->query('SELECT storage_key FROM attachments UNION ALL SELECT preview_storage_key FROM attachments WHERE preview_storage_key IS NOT NULL UNION ALL SELECT storage_key FROM direct_message_attachments UNION ALL SELECT preview_storage_key FROM direct_message_attachments WHERE preview_storage_key IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * JSON objects are unordered, but value types must remain exact.
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        ksort($value);
        return $value;
    }
    private function error(string $code, callable $operation, int $status): void
    {
        try {
            $operation();
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->status);
            return;
        }
        self::fail('Expected ' . $code);
    }
}
