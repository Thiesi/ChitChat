<?php

declare(strict_types=1);

namespace ChitChat\Upload;

/** Reads only JPEG marker/IFD0 orientation metadata; independent of optional GD/EXIF extensions. */
final class JpegOrientation
{
    private const MAX_HEADER_BYTES = 1_048_576;

    public static function read(string $path): ?int
    {
        $data = @file_get_contents($path, false, null, 0, self::MAX_HEADER_BYTES);
        if (!is_string($data) || !str_starts_with($data, "\xff\xd8")) {
            return null;
        }
        $size = strlen($data);
        $offset = 2;
        $orientation = 1;
        $seen = false;
        while ($offset < $size) {
            if (ord($data[$offset]) !== 0xff) {
                return null;
            }
            while ($offset < $size && ord($data[$offset]) === 0xff) {
                $offset++;
            }
            if ($offset >= $size) {
                return null;
            }
            $marker = ord($data[$offset++]);
            if ($marker === 0xda || $marker === 0xd9) {
                return $orientation;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            if ($offset + 2 > $size) {
                return null;
            }
            $length = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
            if ($length < 2 || $offset + $length > $size) {
                return null;
            }
            if ($marker === 0xe1 && substr($data, $offset + 2, 6) === "Exif\0\0") {
                if ($length < 8) { return null; }
                $value = self::ifd(substr($data, $offset + 8, $length - 8));
                if ($value === null || ($seen && $orientation !== $value)) {
                    return null; // Conflicting/malformed orientation is not reliable geometry.
                }
                $orientation = $value;
                $seen = true;
            }
            $offset += $length;
        }
        return null; // Truncated or more than the bounded header budget before the scan.
    }

    private static function ifd(string $data): ?int
    {
        if (strlen($data) < 8) {
            return null;
        }
        $order = substr($data, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $little = $order === 'II';
        if (self::integer($data, 2, 2, $little) !== 42) {
            return null;
        }
        $offset = self::integer($data, 4, 4, $little);
        if ($offset === null || $offset < 8) {
            return null;
        }
        $count = self::integer($data, $offset, 2, $little);
        if ($count === null || $count > 4096 || $offset + 2 + $count * 12 > strlen($data)) {
            return null;
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = $offset + 2 + $index * 12;
            if (self::integer($data, $entry, 2, $little) !== 0x0112) {
                continue;
            }
            if (self::integer($data, $entry + 2, 2, $little) !== 3
                || self::integer($data, $entry + 4, 4, $little) !== 1) {
                return null;
            }
            $orientation = self::integer($data, $entry + 8, 2, $little);
            return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : null;
        }
        return 1;
    }

    private static function integer(string $data, int $offset, int $length, bool $little): ?int
    {
        if ($offset < 0 || $offset + $length > strlen($data)) {
            return null;
        }
        $value = 0;
        for ($index = 0; $index < $length; $index++) {
            $position = $little ? $offset + $length - 1 - $index : $offset + $index;
            $value = ($value << 8) | ord($data[$position]);
        }
        return $value;
    }
}
