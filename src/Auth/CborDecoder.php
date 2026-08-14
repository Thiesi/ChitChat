<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use RuntimeException;

final class CborDecoder
{
    private const MAX_DEPTH = 32;

    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value = self::decodeAt($data, $offset);
        if ($offset !== strlen($data)) {
            throw new RuntimeException('CBOR value contains trailing data.');
        }

        return $value;
    }

    public static function decodeAt(string $data, int &$offset, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('CBOR value nesting exceeds the supported depth.');
        }
        $initial = self::readByte($data, $offset);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;

        if ($major === 7) {
            return match ($additional) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new RuntimeException('Unsupported CBOR simple or floating-point value.'),
            };
        }

        $length = self::readLength($data, $offset, $additional);
        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => self::readBytes($data, $offset, $length),
            3 => self::decodeUtf8(self::readBytes($data, $offset, $length)),
            4 => self::decodeArray($data, $offset, $length, $depth + 1),
            5 => self::decodeMap($data, $offset, $length, $depth + 1),
            6 => self::decodeAt($data, $offset, $depth + 1),
            default => throw new RuntimeException('Unsupported CBOR major type.'),
        };
    }

    /** @return list<mixed> */
    private static function decodeArray(string $data, int &$offset, int $length, int $depth): array
    {
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = self::decodeAt($data, $offset, $depth);
        }

        return $result;
    }

    /** @return array<int|string, mixed> */
    private static function decodeMap(string $data, int &$offset, int $length, int $depth): array
    {
        $result = [];
        $seen = [];
        for ($index = 0; $index < $length; $index++) {
            $key = self::decodeAt($data, $offset, $depth);
            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('CBOR map keys must be integers or strings.');
            }
            $typed = (is_int($key) ? 'i:' : 's:') . (string) $key;
            if (isset($seen[$typed])) {
                throw new RuntimeException('CBOR map contains a duplicate key.');
            }
            $seen[$typed] = true;
            $result[$key] = self::decodeAt($data, $offset, $depth);
        }

        return $result;
    }

    private static function readLength(string $data, int &$offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }
        return match ($additional) {
            24 => self::readUnsigned($data, $offset, 1),
            25 => self::readUnsigned($data, $offset, 2),
            26 => self::readUnsigned($data, $offset, 4),
            27 => self::readUnsigned($data, $offset, 8),
            default => throw new RuntimeException('Indefinite-length or reserved CBOR values are not supported.'),
        };
    }

    private static function readUnsigned(string $data, int &$offset, int $bytes): int
    {
        $encoded = self::readBytes($data, $offset, $bytes);
        $value = 0;
        for ($index = 0; $index < $bytes; $index++) {
            if ($value > intdiv(PHP_INT_MAX - ord($encoded[$index]), 256)) {
                throw new RuntimeException('CBOR integer exceeds the supported range.');
            }
            $value = ($value * 256) + ord($encoded[$index]);
        }

        return $value;
    }

    private static function readByte(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR value is truncated.');
        }

        return ord($data[$offset++]);
    }

    private static function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('CBOR value is truncated.');
        }
        $result = substr($data, $offset, $length);
        $offset += $length;

        return $result;
    }

    private static function decodeUtf8(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('CBOR text string is not valid UTF-8.');
        }

        return $value;
    }
}
