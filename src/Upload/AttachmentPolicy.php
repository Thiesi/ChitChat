<?php

declare(strict_types=1);
namespace ChitChat\Upload;

final class AttachmentPolicy
{
    private const ALLOWED_MIME_TYPES = [
        'application/json',
        'application/pdf',
        'application/zip',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/csv',
        'text/plain',
    ];

    private const PREVIEWABLE_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function isAllowed(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    public static function isPreviewable(string $mimeType): bool
    {
        return in_array($mimeType, self::PREVIEWABLE_MIME_TYPES, true);
    }

    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }
}
