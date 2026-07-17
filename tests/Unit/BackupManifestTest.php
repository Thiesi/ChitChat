<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Backup\BackupException;
use ChitChat\Backup\BackupManifest;
use PHPUnit\Framework\TestCase;

final class BackupManifestTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->manifestPath = sys_get_temp_dir() . '/chitchat-manifest-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
    }

    public function testRoundTripsValidatedManifest(): void
    {
        $manifest = BackupManifest::fromArray($this->validManifest());
        $manifest->write($this->manifestPath);
        $loaded = BackupManifest::load($this->manifestPath);

        self::assertSame('20260717T120000Z-deadbeef', $loaded->backupId());
        self::assertSame('1.1.0', $loaded->applicationVersion());
        self::assertSame('chitchat', $loaded->sourceDatabase());
        self::assertSame(['0013_operational_observability.sql', '0014_rate_limit_observability.sql'], $loaded->migrations());
        self::assertSame('uploads', $loaded->archiveRoot());
        self::assertSame(2, $loaded->attachmentInventory()->fileCount);
        self::assertSame(123, $loaded->file('database.dump')['size_bytes']);
    }

    public function testRejectsUnsafeArchiveRoot(): void
    {
        $data = $this->validManifest();
        /** @var array<string, mixed> $attachments */
        $attachments = $data['attachments'];
        $attachments['archive_root'] = '../uploads';
        $data['attachments'] = $attachments;

        $this->expectException(BackupException::class);
        BackupManifest::fromArray($data);
    }

    public function testRejectsUnsortedMigrationState(): void
    {
        $data = $this->validManifest();
        /** @var array<string, mixed> $database */
        $database = $data['database'];
        $database['migrations'] = ['0014_rate_limit_observability.sql', '0013_operational_observability.sql'];
        $data['database'] = $database;

        $this->expectException(BackupException::class);
        BackupManifest::fromArray($data);
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        return [
            'format' => BackupManifest::FORMAT,
            'format_version' => BackupManifest::FORMAT_VERSION,
            'backup_id' => '20260717T120000Z-deadbeef',
            'created_at' => '2026-07-17T12:00:00+00:00',
            'completed_at' => '2026-07-17T12:00:03+00:00',
            'application' => [
                'name' => 'ChitChat',
                'version' => '1.1.0',
            ],
            'consistency' => [
                'mode' => 'offline',
                'application_writes_stopped' => true,
            ],
            'database' => [
                'source_name' => 'chitchat',
                'server_version' => '16.4',
                'server_encoding' => 'UTF8',
                'source_size_bytes' => 4096,
                'dump_format' => 'postgresql-custom',
                'migrations' => [
                    '0013_operational_observability.sql',
                    '0014_rate_limit_observability.sql',
                ],
            ],
            'attachments' => [
                'archive_format' => 'pax-tar',
                'archive_root' => 'uploads',
                'file_count' => 2,
                'directory_count' => 1,
                'total_bytes' => 42,
            ],
            'files' => [
                'database.dump' => [
                    'sha256' => str_repeat('a', 64),
                    'size_bytes' => 123,
                ],
                'attachments.tar' => [
                    'sha256' => str_repeat('b', 64),
                    'size_bytes' => 456,
                ],
            ],
            'tools' => [
                'pg_dump' => 'pg_dump (PostgreSQL) 16.4',
                'pg_restore' => 'pg_restore (PostgreSQL) 16.4',
                'tar' => 'tar (GNU tar) 1.35',
            ],
            'restore' => [
                'tool_format_version' => BackupManifest::FORMAT_VERSION,
                'recommended_application_version' => '1.1.0',
                'migrations_forward_only' => true,
                'database_strategy' => 'create-empty-database',
                'attachment_strategy' => 'stage-verify-rename',
            ],
        ];
    }
}
