<?php

declare(strict_types=1);

namespace ChitChat\Backup;

use ChitChat\Config;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class BackupManager
{
    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo,
        private readonly ProcessRunner $process,
    ) {
    }

    /** @return array{backup_path:string, manifest:BackupManifest} */
    public function create(string $destinationRoot, bool $applicationWritesStopped): array
    {
        $destinationRoot = $this->validateAbsolutePath($destinationRoot, 'Backup destination');
        $attachmentPath = $this->validateAbsolutePath($this->config->attachmentStoragePath, 'Attachment storage');

        $this->ensureDirectory($destinationRoot, 0700);
        $destinationRoot = $this->canonicalDirectory($destinationRoot, 'Backup destination');
        $attachmentPath = $this->canonicalDirectory($attachmentPath, 'Attachment storage');
        $publicPath = $this->canonicalDirectory(dirname(__DIR__, 2) . '/public', 'Public web root');

        if ($this->isSameOrBelow($destinationRoot, $publicPath)) {
            throw new BackupException('Backup destination must be outside the public web root.');
        }
        if ($this->isSameOrBelow($destinationRoot, $attachmentPath)) {
            throw new BackupException('Backup destination must not be inside attachment storage.');
        }
        if (!is_writable($destinationRoot)) {
            throw new BackupException('Backup destination is not writable: ' . $destinationRoot);
        }

        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $backupId = $createdAt->format('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(4));
        $partialPath = $destinationRoot . '/.' . $backupId . '.partial';
        $finalPath = $destinationRoot . '/' . $backupId;
        if (file_exists($partialPath) || file_exists($finalPath)) {
            throw new BackupException('Backup destination collision for ID: ' . $backupId);
        }

        $this->ensureDirectory($partialPath, 0700);

        try {
            if (!$this->pdo instanceof PDO) {
                throw new BackupException('Creating a backup requires a database connection.');
            }

            $toolVersions = [
                'pg_dump' => $this->firstLine($this->process->run(['pg_dump', '--version'])),
                'pg_restore' => $this->firstLine($this->process->run(['pg_restore', '--version'])),
                'tar' => $this->firstLine($this->process->run(['tar', '--version'])),
            ];
            $databaseBefore = $this->databaseMetadata($this->pdo);
            $inventoryBefore = AttachmentInventory::scan($attachmentPath);
            $databaseDump = $partialPath . '/database.dump';
            $attachmentArchive = $partialPath . '/attachments.tar';

            $this->process->run([
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file',
                $databaseDump,
                '--dbname',
                $this->config->databaseName,
            ], $this->postgresEnvironment());

            $databaseAfter = $this->databaseMetadata($this->pdo);
            if ($databaseBefore['migrations'] !== $databaseAfter['migrations']) {
                throw new BackupException('Database migration state changed during backup. Retry without a concurrent deployment.');
            }
            if ($databaseBefore['server_version'] !== $databaseAfter['server_version']) {
                throw new BackupException('Database server version changed during backup.');
            }

            $archiveRoot = basename($attachmentPath);
            if ($archiveRoot === '' || $archiveRoot === '.' || $archiveRoot === '..') {
                throw new BackupException('Attachment storage must not be a filesystem root.');
            }

            $this->process->run([
                'tar',
                '--format=pax',
                '--create',
                '--file',
                $attachmentArchive,
                '--directory',
                dirname($attachmentPath),
                '--',
                $archiveRoot,
            ]);

            $inventoryAfter = AttachmentInventory::scan($attachmentPath);
            if (!$inventoryBefore->equals($inventoryAfter)) {
                throw new BackupException('Attachment storage changed while it was being archived. Retry after draining writes.');
            }

            $completedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $manifestData = [
                'format' => BackupManifest::FORMAT,
                'format_version' => BackupManifest::FORMAT_VERSION,
                'backup_id' => $backupId,
                'created_at' => $createdAt->format(DATE_ATOM),
                'completed_at' => $completedAt->format(DATE_ATOM),
                'application' => [
                    'name' => $this->config->applicationName,
                    'version' => $this->config->applicationVersion,
                ],
                'consistency' => [
                    'mode' => $applicationWritesStopped ? 'offline' : 'live',
                    'application_writes_stopped' => $applicationWritesStopped,
                ],
                'database' => [
                    'source_name' => $databaseBefore['database_name'],
                    'server_version' => $databaseBefore['server_version'],
                    'server_encoding' => $databaseBefore['server_encoding'],
                    'source_size_bytes' => $databaseBefore['size_bytes'],
                    'dump_format' => 'postgresql-custom',
                    'migrations' => $databaseBefore['migrations'],
                ],
                'attachments' => array_merge(
                    [
                        'archive_format' => 'pax-tar',
                        'archive_root' => $archiveRoot,
                    ],
                    $inventoryAfter->toArray(),
                ),
                'files' => [
                    'database.dump' => $this->fileMetadata($databaseDump),
                    'attachments.tar' => $this->fileMetadata($attachmentArchive),
                ],
                'tools' => $toolVersions,
                'restore' => [
                    'tool_format_version' => BackupManifest::FORMAT_VERSION,
                    'recommended_application_version' => $this->config->applicationVersion,
                    'migrations_forward_only' => true,
                    'database_strategy' => 'create-empty-database',
                    'attachment_strategy' => 'stage-verify-rename',
                ],
            ];

            /** @var array<string, mixed> $manifestData */
            $manifest = BackupManifest::fromArray($manifestData);
            $manifest->write($partialPath . '/manifest.json');
            $this->verify($partialPath);

            if (!rename($partialPath, $finalPath)) {
                throw new BackupException('Unable to publish completed backup directory.');
            }

            return [
                'backup_path' => $finalPath,
                'manifest' => $manifest,
            ];
        } catch (Throwable $exception) {
            $this->recursiveDelete($partialPath);
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException($exception->getMessage(), 0, $exception);
        }
    }

    public function verify(string $backupPath): BackupManifest
    {
        $backupPath = $this->validateAbsolutePath($backupPath, 'Backup path');
        if (is_link($backupPath)) {
            throw new BackupException('Backup path must not be a symbolic link: ' . $backupPath);
        }
        if (!is_dir($backupPath) || !is_readable($backupPath)) {
            throw new BackupException('Backup path is not a readable directory: ' . $backupPath);
        }
        if (is_link($backupPath . '/manifest.json')) {
            throw new BackupException('Backup manifest must not be a symbolic link.');
        }

        $manifest = BackupManifest::load($backupPath . '/manifest.json');
        foreach (['database.dump', 'attachments.tar'] as $fileName) {
            $path = $backupPath . '/' . $fileName;
            if (is_link($path)) {
                throw new BackupException('Backup file must not be a symbolic link: ' . $fileName);
            }
            if (!is_file($path) || !is_readable($path)) {
                throw new BackupException('Backup file is missing or unreadable: ' . $fileName);
            }

            $expected = $manifest->file($fileName);
            $size = filesize($path);
            if ($size === false || $size !== $expected['size_bytes']) {
                throw new BackupException('Backup file size differs from manifest: ' . $fileName);
            }
            $checksum = hash_file('sha256', $path);
            if (!is_string($checksum) || !hash_equals($expected['sha256'], $checksum)) {
                throw new BackupException('Backup file checksum differs from manifest: ' . $fileName);
            }
        }

        $this->process->run(['pg_restore', '--list', $backupPath . '/database.dump']);
        $this->verifyAttachmentArchive($backupPath . '/attachments.tar', $manifest->archiveRoot());

        return $manifest;
    }

    /**
     * @return array{
     *   database:string,
     *   attachments:string,
     *   previous_attachments:?string,
     *   application_version:string,
     *   migrations:list<string>
     * }
     */
    public function restore(
        string $backupPath,
        string $targetDatabase,
        string $targetAttachments,
        bool $dropExistingDatabase,
        bool $replaceAttachments,
        bool $allowCurrentTarget,
    ): array {
        $manifest = $this->verify($backupPath);
        $targetDatabase = $this->validateDatabaseName($targetDatabase);
        $targetAttachments = $this->validateAbsolutePath($targetAttachments, 'Target attachment path');
        $targetParent = dirname($targetAttachments);
        $this->ensureDirectory($targetParent, 0700, false);
        $targetParent = $this->canonicalDirectory($targetParent, 'Target attachment parent');
        $targetAttachments = $targetParent . '/' . basename($targetAttachments);
        $publicPath = $this->canonicalDirectory(dirname(__DIR__, 2) . '/public', 'Public web root');

        if (
            $this->isSameOrBelow($targetAttachments, $publicPath)
            || $this->isSameOrBelow($publicPath, $targetAttachments)
        ) {
            throw new BackupException('Target attachment path must not overlap the public web root.');
        }
        if (!$allowCurrentTarget) {
            if ($targetDatabase === $this->config->databaseName) {
                throw new BackupException('Refusing to restore over the configured database without --allow-current-target.');
            }
            $configuredAttachments = realpath($this->config->attachmentStoragePath);
            $configuredAttachments = $configuredAttachments === false
                ? $this->normalizePath($this->config->attachmentStoragePath)
                : $this->normalizePath($configuredAttachments);
            if (
                $this->isSameOrBelow($targetAttachments, $configuredAttachments)
                || $this->isSameOrBelow($configuredAttachments, $targetAttachments)
            ) {
                throw new BackupException('Refusing to restore into, over, or above configured attachment storage without --allow-current-target.');
            }
        }
        if (is_link($targetAttachments)) {
            throw new BackupException('Target attachment path must not be a symbolic link.');
        }
        if (file_exists($targetAttachments) && !$replaceAttachments) {
            throw new BackupException('Target attachment path already exists; use --replace-attachments to preserve and replace it.');
        }

        $stagePath = $targetParent . '/.' . basename($targetAttachments) . '.restore-' . bin2hex(random_bytes(4));
        $previousAttachments = null;
        $databaseCreated = false;
        $attachmentsInstalled = false;
        $this->ensureDirectory($stagePath, 0700);

        try {
            $this->process->run([
                'tar',
                '--extract',
                '--file',
                $backupPath . '/attachments.tar',
                '--directory',
                $stagePath,
                '--no-same-owner',
            ]);

            $extractedPath = $stagePath . '/' . $manifest->archiveRoot();
            if (!is_dir($extractedPath)) {
                throw new BackupException('Attachment archive did not produce the expected root directory.');
            }
            $restoredInventory = AttachmentInventory::scan($extractedPath);
            if (!$restoredInventory->equals($manifest->attachmentInventory())) {
                throw new BackupException('Restored attachment inventory differs from the manifest.');
            }

            if ($dropExistingDatabase) {
                $this->process->run([
                    'dropdb',
                    '--if-exists',
                    '--maintenance-db',
                    'postgres',
                    $targetDatabase,
                ], $this->postgresEnvironment());
            }

            $this->process->run([
                'createdb',
                '--maintenance-db',
                'postgres',
                $targetDatabase,
            ], $this->postgresEnvironment());
            $databaseCreated = true;

            $this->process->run([
                'pg_restore',
                '--exit-on-error',
                '--no-owner',
                '--no-privileges',
                '--dbname',
                $targetDatabase,
                $backupPath . '/database.dump',
            ], $this->postgresEnvironment());

            $restoredPdo = $this->connectToDatabase($targetDatabase);
            $restoredDatabase = $this->databaseMetadata($restoredPdo);
            unset($restoredPdo);
            if ($restoredDatabase['migrations'] !== $manifest->migrations()) {
                throw new BackupException('Restored database migration state differs from the manifest.');
            }

            if (file_exists($targetAttachments)) {
                $previousAttachments = $targetAttachments . '.pre-restore-' . gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(3));
                if (file_exists($previousAttachments)) {
                    throw new BackupException('Preserved attachment path already exists: ' . $previousAttachments);
                }
                if (!rename($targetAttachments, $previousAttachments)) {
                    throw new BackupException('Unable to preserve existing attachment storage.');
                }
            }

            if (!rename($extractedPath, $targetAttachments)) {
                if ($previousAttachments !== null && !file_exists($targetAttachments)) {
                    @rename($previousAttachments, $targetAttachments);
                    $previousAttachments = null;
                }
                throw new BackupException('Unable to install restored attachment storage.');
            }
            $attachmentsInstalled = true;
            if (!chmod($targetAttachments, 0700)) {
                throw new BackupException('Unable to restrict restored attachment directory permissions.');
            }

            $this->recursiveDelete($stagePath);

            return [
                'database' => $targetDatabase,
                'attachments' => $targetAttachments,
                'previous_attachments' => $previousAttachments,
                'application_version' => $manifest->applicationVersion(),
                'migrations' => $manifest->migrations(),
            ];
        } catch (Throwable $exception) {
            $this->recursiveDelete($stagePath);

            if ($attachmentsInstalled) {
                $this->recursiveDelete($targetAttachments);
                if ($previousAttachments !== null && file_exists($previousAttachments)) {
                    @rename($previousAttachments, $targetAttachments);
                }
            }

            if ($databaseCreated) {
                try {
                    $this->process->run([
                        'dropdb',
                        '--if-exists',
                        '--maintenance-db',
                        'postgres',
                        $targetDatabase,
                    ], $this->postgresEnvironment());
                } catch (Throwable) {
                    // Preserve the original failure; operators can remove the partial database manually.
                }
            }

            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array{
     *   database_name:string,
     *   server_version:string,
     *   server_encoding:string,
     *   size_bytes:int,
     *   migrations:list<string>
     * }
     */
    private function databaseMetadata(PDO $pdo): array
    {
        $statement = $pdo->query(<<<'SQL'
SELECT current_database() AS database_name,
       current_setting('server_version') AS server_version,
       current_setting('server_encoding') AS server_encoding,
       pg_database_size(current_database())::bigint AS size_bytes,
       to_regclass('public.schema_migrations') IS NOT NULL AS has_migrations
SQL);
        if ($statement === false) {
            throw new BackupException('Unable to read database metadata.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new BackupException('Database metadata query returned no row.');
        }

        $hasMigrations = $row['has_migrations'] === true
            || $row['has_migrations'] === 't'
            || $row['has_migrations'] === '1'
            || $row['has_migrations'] === 1;
        $migrations = [];

        if ($hasMigrations) {
            $migrationStatement = $pdo->query('SELECT version FROM schema_migrations ORDER BY version');
            if ($migrationStatement === false) {
                throw new BackupException('Unable to read database migration state.');
            }
            $versions = $migrationStatement->fetchAll(PDO::FETCH_COLUMN);
            foreach ($versions as $version) {
                if (!is_string($version)) {
                    throw new BackupException('Database migration state contains an invalid version.');
                }
                $migrations[] = $version;
            }
        }

        return [
            'database_name' => (string) $row['database_name'],
            'server_version' => (string) $row['server_version'],
            'server_encoding' => (string) $row['server_encoding'],
            'size_bytes' => (int) $row['size_bytes'],
            'migrations' => $migrations,
        ];
    }

    private function connectToDatabase(string $databaseName): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $this->config->databaseHost,
            $this->config->databasePort,
            $databaseName,
            $this->config->databaseSslMode,
        );

        return new PDO(
            $dsn,
            $this->config->databaseUser,
            $this->config->databasePassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function verifyAttachmentArchive(string $archivePath, string $archiveRoot): void
    {
        $pathOutput = trim($this->process->run(['tar', '--list', '--file', $archivePath]));
        if ($pathOutput === '') {
            throw new BackupException('Attachment archive is empty.');
        }

        $rootFound = false;
        $paths = preg_split('/\r?\n/', $pathOutput);
        if (!is_array($paths)) {
            throw new BackupException('Unable to parse attachment archive listing.');
        }

        foreach ($paths as $path) {
            $path = rtrim($path, '/');
            if ($path === '') {
                throw new BackupException('Attachment archive contains an empty path.');
            }
            if (str_starts_with($path, '/') || str_contains($path, "\0")) {
                throw new BackupException('Attachment archive contains an absolute or invalid path.');
            }

            $segments = explode('/', $path);
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new BackupException('Attachment archive contains an unsafe path: ' . $path);
                }
            }
            if ($segments[0] !== $archiveRoot) {
                throw new BackupException('Attachment archive contains data outside its declared root.');
            }
            if ($path === $archiveRoot) {
                $rootFound = true;
            }
        }

        if (!$rootFound) {
            throw new BackupException('Attachment archive does not contain its declared root directory.');
        }

        $verboseOutput = trim($this->process->run(['tar', '--list', '--verbose', '--file', $archivePath]));
        $verboseLines = preg_split('/\r?\n/', $verboseOutput);
        if (!is_array($verboseLines) || $verboseLines === []) {
            throw new BackupException('Unable to inspect attachment archive entry types.');
        }
        foreach ($verboseLines as $line) {
            if ($line === '') {
                continue;
            }
            $type = $line[0];
            if ($type !== '-' && $type !== 'd') {
                throw new BackupException('Attachment archive contains a non-regular entry type.');
            }
        }
    }

    /** @return array{sha256:string, size_bytes:int} */
    private function fileMetadata(string $path): array
    {
        $checksum = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($checksum) || $size === false) {
            throw new BackupException('Unable to inspect generated backup file: ' . $path);
        }

        return [
            'sha256' => $checksum,
            'size_bytes' => $size,
        ];
    }

    /** @return array<string, string> */
    private function postgresEnvironment(): array
    {
        return [
            'PGHOST' => $this->config->databaseHost,
            'PGPORT' => (string) $this->config->databasePort,
            'PGUSER' => $this->config->databaseUser,
            'PGPASSWORD' => $this->config->databasePassword,
            'PGSSLMODE' => $this->config->databaseSslMode,
        ];
    }

    private function validateDatabaseName(string $databaseName): string
    {
        $databaseName = trim($databaseName);
        if (preg_match('/\A[A-Za-z0-9_.-]{1,63}\z/', $databaseName) !== 1) {
            throw new BackupException('Target database name must contain 1-63 letters, numbers, dots, underscores, or hyphens.');
        }

        return $databaseName;
    }

    private function validateAbsolutePath(string $path, string $label): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new BackupException($label . ' must be an absolute Unix path.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new BackupException($label . ' must not contain control characters.');
        }
        if (in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new BackupException($label . ' must not contain parent-directory segments.');
        }

        $normalized = $this->normalizePath($path);
        if ($normalized === '/') {
            throw new BackupException($label . ' must not be the filesystem root.');
        }

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private function isSameOrBelow(string $path, string $parent): bool
    {
        $path = $this->normalizePath($path);
        $parent = $this->normalizePath($parent);

        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private function ensureDirectory(string $path, int $mode, bool $restrictExisting = true): void
    {
        $created = false;
        if (!is_dir($path)) {
            if (!mkdir($path, $mode, true) && !is_dir($path)) {
                throw new BackupException('Unable to create directory: ' . $path);
            }
            $created = true;
        }
        if (($created || $restrictExisting) && !chmod($path, $mode)) {
            throw new BackupException('Unable to set directory permissions: ' . $path);
        }
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new BackupException($label . ' is not an accessible directory: ' . $path);
        }

        return $this->normalizePath($resolved);
    }

    private function recursiveDelete(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!file_exists($path) || !is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function firstLine(string $output): string
    {
        $lines = preg_split('/\r?\n/', trim($output));
        if (!is_array($lines) || ($lines[0] ?? '') === '') {
            throw new BackupException('Unable to determine external tool version.');
        }

        return $lines[0];
    }
}
