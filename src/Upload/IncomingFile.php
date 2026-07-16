<?php

declare(strict_types=1);
namespace ChitChat\Upload;

use ChitChat\Http\ApiException;
use RuntimeException;

final readonly class IncomingFile
{
    private function __construct(
        public string $originalName,
        public string $temporaryPath,
        public int $reportedSize,
        private bool $localTestFile,
    ) {
    }

    public static function fromGlobal(string $field): self
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            throw new ApiException(400, 'attachment_missing', 'Choose a file to upload.');
        }

        $name = $file['name'] ?? null;
        $temporaryPath = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        $error = $file['error'] ?? null;
        if (!is_string($name) || !is_string($temporaryPath) || !is_int($size) || !is_int($error)) {
            throw new ApiException(400, 'invalid_attachment_upload', 'The uploaded file metadata is invalid.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw self::uploadError($error);
        }
        if (!is_uploaded_file($temporaryPath)) {
            throw new ApiException(400, 'invalid_attachment_upload', 'The upload temporary file is invalid.');
        }

        return new self($name, $temporaryPath, $size, false);
    }

    public static function forTesting(string $originalName, string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Test upload source is not a readable file.');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Unable to determine test upload size.');
        }

        return new self($originalName, $path, $size, true);
    }

    public function moveTo(string $destination): void
    {
        if (file_exists($destination)) {
            throw new RuntimeException('Attachment storage destination already exists.');
        }

        $moved = $this->localTestFile
            ? rename($this->temporaryPath, $destination)
            : move_uploaded_file($this->temporaryPath, $destination);
        if (!$moved) {
            throw new RuntimeException('Unable to move the uploaded file into attachment storage.');
        }
        if (!chmod($destination, 0600)) {
            @unlink($destination);
            throw new RuntimeException('Unable to secure the stored attachment permissions.');
        }
    }

    private static function uploadError(int $error): ApiException
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => new ApiException(
                413,
                'attachment_too_large',
                'The uploaded file exceeds the server upload limit.',
            ),
            UPLOAD_ERR_PARTIAL => new ApiException(400, 'attachment_incomplete', 'The file upload was incomplete.'),
            UPLOAD_ERR_NO_FILE => new ApiException(400, 'attachment_missing', 'Choose a file to upload.'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => new ApiException(
                500,
                'attachment_storage_unavailable',
                'The server could not accept the uploaded file.',
            ),
            default => new ApiException(400, 'invalid_attachment_upload', 'The file upload failed.'),
        };
    }
}
