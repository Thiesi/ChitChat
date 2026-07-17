<?php

declare(strict_types=1);
namespace ChitChat\Upload;

use ChitChat\Config;
use ChitChat\Http\ApiException;
use finfo;
use RuntimeException;

final class AttachmentFileStore
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{
     *   storage_key:string,
     *   path:string,
     *   name:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   sha256:string,
     *   previewable:bool
     * }
     */
    public function store(IncomingFile $file): array
    {
        $name = $this->normalizeOriginalName($file->originalName);
        if ($file->reportedSize < 1) {
            throw new ApiException(400, 'attachment_empty', 'Empty files cannot be uploaded.');
        }
        if ($file->reportedSize > $this->config->attachmentMaxBytes) {
            throw new ApiException(413, 'attachment_too_large', 'The attachment exceeds the configured size limit.');
        }

        $mimeType = $this->detectMimeType($file->temporaryPath);
        if (!AttachmentPolicy::isAllowed($mimeType)) {
            throw new ApiException(
                415,
                'attachment_type_not_allowed',
                'That file type is not allowed for attachments.',
            );
        }

        $storageKey = bin2hex(random_bytes(32));
        $path = $this->pathForKey($storageKey);
        $this->prepareStorageDirectory($path);
        $file->moveTo($path);

        try {
            $actualSize = filesize($path);
            if ($actualSize === false || $actualSize < 1) {
                throw new RuntimeException('Unable to determine stored attachment size.');
            }
            if ($actualSize > $this->config->attachmentMaxBytes) {
                throw new ApiException(413, 'attachment_too_large', 'The attachment exceeds the configured size limit.');
            }
            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                throw new RuntimeException('Unable to hash the stored attachment.');
            }

            return [
                'storage_key' => $storageKey,
                'path' => $path,
                'name' => $name,
                'mime_type' => $mimeType,
                'size_bytes' => $actualSize,
                'sha256' => $sha256,
                'previewable' => AttachmentPolicy::isPreviewable($mimeType),
            ];
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    public function resolve(string $storageKey, int $expectedSize): string
    {
        $path = $this->pathForKey($storageKey);
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException(410, 'attachment_storage_missing', 'The attachment file is unavailable.');
        }
        $actualSize = filesize($path);
        if ($actualSize === false || $actualSize !== $expectedSize) {
            throw new ApiException(410, 'attachment_storage_invalid', 'The attachment file failed an integrity check.');
        }

        return $path;
    }

    public function remove(string $storageKey): void
    {
        $path = $this->pathForKey($storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function pathForKey(string $storageKey): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $storageKey) !== 1) {
            throw new RuntimeException('Stored attachment key is invalid.');
        }
        $base = rtrim($this->config->attachmentStoragePath, '/\\');

        return $base
            . DIRECTORY_SEPARATOR . substr($storageKey, 0, 2)
            . DIRECTORY_SEPARATOR . substr($storageKey, 2, 2)
            . DIRECTORY_SEPARATOR . $storageKey;
    }

    private function detectMimeType(string $path): string
    {
        $detector = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $detector->file($path);
        if (!is_string($mimeType) || $mimeType === '') {
            throw new ApiException(415, 'attachment_type_unknown', 'The file type could not be determined.');
        }

        return strtolower($mimeType);
    }

    private function normalizeOriginalName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = 'attachment';
        }
        $name = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(is_string($clean) ? $clean : '');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }
        if (mb_strlen($name, 'UTF-8') > 255) {
            $name = mb_substr($name, 0, 255, 'UTF-8');
        }

        return $name;
    }

    private function prepareStorageDirectory(string $storagePath): void
    {
        $base = rtrim($this->config->attachmentStoragePath, '/\\');
        foreach ([$base, dirname(dirname($storagePath)), dirname($storagePath)] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('Attachment storage directories must not be symbolic links.');
            }
            if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create attachment storage directory.');
            }
            if (!is_writable($directory)) {
                throw new RuntimeException('Attachment storage directory is not writable.');
            }
        }
    }
}
