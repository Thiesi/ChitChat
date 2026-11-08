<?php

declare(strict_types=1);
namespace ChitChat\Upload;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;

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

    /** @return array<string, mixed>|null */
    public static function forSession(Config $config, ?AuthenticatedUser $actor): ?array
    {
        if ($actor === null || $actor->guest) {
            return null;
        }
        $rateLimits = [];
        foreach (['room' => 'attachment_upload', 'direct_message' => 'direct_message_attachment_upload'] as $kind => $name) {
            $policy = $config->rateLimitPolicy($name);
            $rateLimits[$kind] = [
                'maximum_attempts' => $policy->maximumAttempts,
                'window_seconds' => $policy->windowSeconds,
            ];
        }

        return [
            'max_bytes' => AttachmentUploadLimits::fromConfig($config)->maxBytes,
            'allowed_mime_types' => self::ALLOWED_MIME_TYPES,
            'previewable_mime_types' => self::PREVIEWABLE_MIME_TYPES,
            'rate_limits' => $rateLimits,
        ];
    }

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
