<?php

declare(strict_types=1);

namespace ChitChat\Backup;

use DateTimeImmutable;
use JsonException;

final readonly class BackupManifest
{
    public const FORMAT = 'chitchat-backup';
    public const FORMAT_VERSION = 1;

    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::validate($data);

        return new self($data);
    }

    public static function load(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new BackupException('Unable to read backup manifest: ' . $path);
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupException('Backup manifest is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new BackupException('Backup manifest root must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    public function write(string $path): void
    {
        try {
            $json = json_encode(
                $this->data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BackupException('Unable to encode backup manifest: ' . $exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new BackupException('Unable to write backup manifest: ' . $path);
        }
        if (!chmod($path, 0600)) {
            throw new BackupException('Unable to restrict backup manifest permissions: ' . $path);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function backupId(): string
    {
        return self::stringAt($this->data, 'backup_id');
    }

    public function applicationVersion(): string
    {
        return self::stringAt(self::objectAt($this->data, 'application'), 'version');
    }

    public function sourceDatabase(): string
    {
        return self::stringAt(self::objectAt($this->data, 'database'), 'source_name');
    }

    /** @return list<string> */
    public function migrations(): array
    {
        $migrations = self::listAt(self::objectAt($this->data, 'database'), 'migrations');
        /** @var list<string> $migrations */
        return $migrations;
    }

    public function archiveRoot(): string
    {
        return self::stringAt(self::objectAt($this->data, 'attachments'), 'archive_root');
    }

    public function attachmentInventory(): AttachmentInventory
    {
        $attachments = self::objectAt($this->data, 'attachments');

        return new AttachmentInventory(
            self::intAt($attachments, 'file_count'),
            self::intAt($attachments, 'directory_count'),
            self::intAt($attachments, 'total_bytes'),
        );
    }

    /** @return array{sha256:string, size_bytes:int} */
    public function file(string $name): array
    {
        $files = self::objectAt($this->data, 'files');
        $entry = self::objectAt($files, $name);

        return [
            'sha256' => self::stringAt($entry, 'sha256'),
            'size_bytes' => self::intAt($entry, 'size_bytes'),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function validate(array $data): void
    {
        if (self::stringAt($data, 'format') !== self::FORMAT) {
            throw new BackupException('Unsupported backup manifest format.');
        }
        if (self::intAt($data, 'format_version') !== self::FORMAT_VERSION) {
            throw new BackupException('Unsupported backup manifest version.');
        }

        $backupId = self::stringAt($data, 'backup_id');
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/', $backupId) !== 1) {
            throw new BackupException('Backup manifest contains an invalid backup ID.');
        }

        $createdAt = self::dateAt($data, 'created_at');
        $completedAt = self::dateAt($data, 'completed_at');
        if ($completedAt < $createdAt) {
            throw new BackupException('Backup completion time precedes creation time.');
        }

        $application = self::objectAt($data, 'application');
        self::nonEmpty(self::stringAt($application, 'name'), 'application.name');
        self::nonEmpty(self::stringAt($application, 'version'), 'application.version');

        $consistency = self::objectAt($data, 'consistency');
        $mode = self::stringAt($consistency, 'mode');
        if (!in_array($mode, ['live', 'offline'], true)) {
            throw new BackupException('Backup consistency mode must be live or offline.');
        }
        $writesStopped = self::boolAt($consistency, 'application_writes_stopped');
        if (($mode === 'offline') !== $writesStopped) {
            throw new BackupException('Backup consistency metadata is contradictory.');
        }

        $database = self::objectAt($data, 'database');
        self::nonEmpty(self::stringAt($database, 'source_name'), 'database.source_name');
        self::nonEmpty(self::stringAt($database, 'server_version'), 'database.server_version');
        self::nonEmpty(self::stringAt($database, 'server_encoding'), 'database.server_encoding');
        if (self::stringAt($database, 'dump_format') !== 'postgresql-custom') {
            throw new BackupException('Backup database dump format is unsupported.');
        }
        if (self::intAt($database, 'source_size_bytes') < 0) {
            throw new BackupException('Database source size cannot be negative.');
        }

        $migrations = self::listAt($database, 'migrations');
        foreach ($migrations as $migration) {
            if (!is_string($migration) || preg_match('/\A[0-9]{4}_[A-Za-z0-9_.-]+\.sql\z/', $migration) !== 1) {
                throw new BackupException('Backup manifest contains an invalid migration version.');
            }
        }
        $sortedMigrations = $migrations;
        sort($sortedMigrations, SORT_STRING);
        if ($migrations !== $sortedMigrations || count($migrations) !== count(array_unique($migrations))) {
            throw new BackupException('Backup migration versions must be unique and sorted.');
        }

        $attachments = self::objectAt($data, 'attachments');
        if (self::stringAt($attachments, 'archive_format') !== 'pax-tar') {
            throw new BackupException('Backup attachment archive format is unsupported.');
        }
        $archiveRoot = self::stringAt($attachments, 'archive_root');
        if (
            $archiveRoot === ''
            || $archiveRoot === '.'
            || $archiveRoot === '..'
            || str_contains($archiveRoot, '/')
            || str_contains($archiveRoot, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $archiveRoot) === 1
        ) {
            throw new BackupException('Backup attachment archive root is unsafe.');
        }
        foreach (['file_count', 'directory_count', 'total_bytes'] as $field) {
            if (self::intAt($attachments, $field) < 0) {
                throw new BackupException('Attachment inventory values cannot be negative.');
            }
        }

        $files = self::objectAt($data, 'files');
        foreach (['database.dump', 'attachments.tar'] as $fileName) {
            $entry = self::objectAt($files, $fileName);
            if (preg_match('/\A[a-f0-9]{64}\z/', self::stringAt($entry, 'sha256')) !== 1) {
                throw new BackupException('Backup file checksum is invalid: ' . $fileName);
            }
            if (self::intAt($entry, 'size_bytes') < 0) {
                throw new BackupException('Backup file size cannot be negative: ' . $fileName);
            }
        }

        $tools = self::objectAt($data, 'tools');
        foreach (['pg_dump', 'pg_restore', 'tar'] as $tool) {
            self::nonEmpty(self::stringAt($tools, $tool), 'tools.' . $tool);
        }

        $restore = self::objectAt($data, 'restore');
        if (self::intAt($restore, 'tool_format_version') !== self::FORMAT_VERSION) {
            throw new BackupException('Backup restore metadata requires an unsupported tool version.');
        }
        self::nonEmpty(self::stringAt($restore, 'recommended_application_version'), 'restore.recommended_application_version');
        if (!self::boolAt($restore, 'migrations_forward_only')) {
            throw new BackupException('Backup restore metadata must preserve forward-only migration semantics.');
        }
        if (self::stringAt($restore, 'database_strategy') !== 'create-empty-database') {
            throw new BackupException('Backup database restore strategy is unsupported.');
        }
        if (self::stringAt($restore, 'attachment_strategy') !== 'stage-verify-rename') {
            throw new BackupException('Backup attachment restore strategy is unsupported.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function objectAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new BackupException('Backup manifest field must be an object: ' . $key);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private static function listAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new BackupException('Backup manifest field must be a list: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function stringAt(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new BackupException('Backup manifest field must be a string: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function intAt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new BackupException('Backup manifest field must be an integer: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function boolAt(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new BackupException('Backup manifest field must be a boolean: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function dateAt(array $data, string $key): DateTimeImmutable
    {
        $value = self::stringAt($data, $key);
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if (!$date instanceof DateTimeImmutable || $date->format(DATE_ATOM) !== $value) {
            throw new BackupException('Backup manifest field must be an ISO-8601 timestamp: ' . $key);
        }

        return $date;
    }

    private static function nonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new BackupException('Backup manifest field may not be empty: ' . $field);
        }
    }
}
