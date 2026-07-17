<?php

declare(strict_types=1);

namespace ChitChat\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class AttachmentInventory
{
    public function __construct(
        public int $fileCount,
        public int $directoryCount,
        public int $totalBytes,
    ) {
        if ($this->fileCount < 0 || $this->directoryCount < 0 || $this->totalBytes < 0) {
            throw new BackupException('Attachment inventory values cannot be negative.');
        }
    }

    public static function scan(string $path): self
    {
        if (is_link($path)) {
            throw new BackupException('Attachment storage root must not be a symbolic link: ' . $path);
        }
        if (!is_dir($path) || !is_readable($path)) {
            throw new BackupException('Attachment storage is not a readable directory: ' . $path);
        }

        $files = 0;
        $directories = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (preg_match('/[\x00-\x1F\x7F]/', $entry->getFilename()) === 1) {
                throw new BackupException('Attachment storage contains a control character in a name: ' . $entry->getPathname());
            }
            if ($entry->isLink()) {
                throw new BackupException('Attachment storage contains a symbolic link: ' . $entry->getPathname());
            }
            if ($entry->isDir()) {
                $directories++;
                continue;
            }
            if (!$entry->isFile()) {
                throw new BackupException('Attachment storage contains a non-regular entry: ' . $entry->getPathname());
            }

            $size = $entry->getSize();
            if ($size === false) {
                throw new BackupException('Unable to read attachment size: ' . $entry->getPathname());
            }
            $files++;
            $bytes += $size;
        }

        return new self($files, $directories, $bytes);
    }

    /** @return array{file_count:int, directory_count:int, total_bytes:int} */
    public function toArray(): array
    {
        return [
            'file_count' => $this->fileCount,
            'directory_count' => $this->directoryCount,
            'total_bytes' => $this->totalBytes,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->fileCount === $other->fileCount
            && $this->directoryCount === $other->directoryCount
            && $this->totalBytes === $other->totalBytes;
    }
}
