<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use ChitChat\Http\ApiException;

final class AttachmentVariant
{
    public static function normalize(mixed $value): string
    {
        if ($value === null) {
            return 'original';
        }
        if (!is_string($value) || !in_array($value, ['original', 'preview'], true)) {
            throw new ApiException(400, 'validation_error', 'variant must be original or preview.');
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{path:string,name:string,mime_type:string,size_bytes:int,sha256:string,previewable:bool,width:?int,height:?int,preview:?array{width:int,height:int,mime_type:string,size_bytes:int,sha256:string}}
     */
    public static function preview(AttachmentFileStore $files, array $row): array
    {
        if (!is_string($row['preview_storage_key'] ?? null)) {
            throw new ApiException(404, 'attachment_preview_unavailable', 'No preview rendition is available for this attachment.');
        }
        return ['path' => $files->resolve($row['preview_storage_key'], (int) $row['preview_size_bytes']),
            'name' => (string) $row['original_name'] . '.preview.webp',
            'mime_type' => 'image/webp', 'size_bytes' => (int) $row['preview_size_bytes'],
            'sha256' => (string) $row['preview_sha256'], 'previewable' => true,
            ...AttachmentImageMetadata::publicFields($row)];
    }
}
