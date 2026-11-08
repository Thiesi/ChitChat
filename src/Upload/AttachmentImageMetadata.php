<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use PDO;
use RuntimeException;

/**
 * @phpstan-type Preview array{width:int,height:int,mime_type:string,size_bytes:int,sha256:string}
 * @phpstan-type ImageFields array{width:?int,height:?int,preview:?Preview}
 * @phpstan-type StoredImage array{image_width:?int,image_height:?int,preview_storage_key:?string,preview_width:?int,preview_height:?int,preview_size_bytes:?int,preview_sha256:?string}
 */
final class AttachmentImageMetadata
{
    public const COLUMNS = ['image_width', 'image_height', 'preview_storage_key', 'preview_width',
        'preview_height', 'preview_size_bytes', 'preview_sha256'];

    /** @return StoredImage */
    public static function emptyStored(): array
    {
        return ['image_width' => null, 'image_height' => null, 'preview_storage_key' => null,
            'preview_width' => null, 'preview_height' => null, 'preview_size_bytes' => null, 'preview_sha256' => null];
    }

    /**
     * @param array<string,mixed> $row
     * @return ImageFields
     */
    public static function publicFields(array $row, string $prefix = ''): array
    {
        $width = $row[$prefix . 'image_width'] ?? null;
        $height = $row[$prefix . 'image_height'] ?? null;
        $sha = $row[$prefix . 'preview_sha256'] ?? null;
        return ['width' => $width === null ? null : (int) $width,
            'height' => $height === null ? null : (int) $height,
            'preview' => !is_string($sha) ? null : [
                'width' => (int) $row[$prefix . 'preview_width'],
                'height' => (int) $row[$prefix . 'preview_height'],
                'mime_type' => 'image/webp',
                'size_bytes' => (int) $row[$prefix . 'preview_size_bytes'],
                'sha256' => $sha,
            ]];
    }

    /** Trusted identifiers only; central projection prevents read-path drift. */
    public static function selectColumns(string $alias, string $prefix = ''): string
    {
        foreach ([$alias, $prefix] as $identifier) {
            if (preg_match('/\A[a-z_]*\z/D', $identifier) !== 1) {
                throw new RuntimeException('Invalid attachment image column qualifier.');
            }
        }
        return implode(",\n       ", array_map(
            static fn (string $column): string => $alias . '.' . $column . ' AS ' . $prefix . $column,
            self::COLUMNS,
        ));
    }

    /** @param array<string,mixed> $stored */
    public static function save(PDO $pdo, string $table, int $id, array $stored): void
    {
        if (!$pdo->inTransaction() || !in_array($table, ['attachments', 'direct_message_attachments'], true)) {
            throw new RuntimeException('Image metadata writes require a known attachment table and transaction.');
        }
        $sets = array_map(static fn (string $column): string => $column . ' = :' . $column, self::COLUMNS);
        $statement = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare attachment image metadata.');
        }
        $parameters = ['id' => $id];
        foreach (self::COLUMNS as $column) {
            $parameters[$column] = $stored[$column] ?? null;
        }
        $statement->execute($parameters);
    }
}
