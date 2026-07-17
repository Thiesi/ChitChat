<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use RuntimeException;

final class CborDecoder
{
    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value = self::decodeAt($data, $offset);
        if ($offset !== strlen($data)) {
            throw new RuntimeException('CBOR value contains trailing data.');
        }

        return $value;
    }

    public static function decodeAt(string $data, int &$offset): mixed
    {
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
            4 => self::decodeArray($data, $offset, $length),
            5 => self::decodeMap($data, $offset, $length),
            6 => self::decodeAt($data, $offset),
            default => throw new RuntimeException('Unsupported CBOR major type.'),
        };
    }

    /** @return list<mixed> */
    private static function decodeArray(string $data, int &$offset, int $length): array
    {
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = self::decodeAt($data, $offset);
        }

        return $result;
    }

    /** @return array<int|string, mixed> */
    private static function decodeMap(string $data, int &$offset, int $length): array
    {
        $result = [];
        $seen = [];
        for ($index = 0; $index < $length; $index++) {
            $key = self::decodeAt($data, $offset);
            if (!is_int($key) && !is_string($key)) {
                throw new RuntimeException('CBOR map keys must be integers or strings.');
            }
            $typed = (is_int($key) ? 'i:' : 's:') . (string) $key;
            if (isset($seen[$typed])) {
                throw new RuntimeException('CBOR map contains a duplicate key.');
            }
            $seen[$typed] = true;
            $result[$key] = self::decodeAt($data, $offset);
        }

        return $result;
    }

    private static function readLength(string $data, int &$offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }
        if ($additional === 24) {
            return self::readByte($data, $offset);
        }
        if ($additional === 25) {
            return unpack('n', self::readBytes($data, $offset, 2))[1];
        }
        if ($additional === 26) {
            return unpack('N', self::readBytes($data, $offset, 4))[1];
        }
        if ($additional === 27) {
            $parts = unpack('Nhigh/Nlow', self::readBytes($data, $offset, 8));
            if ($parts['high'] > 0x7fffffff) {
                throw new RuntimeException('CBOR integer exceeds the supported range.');
            }
            return ($parts['high'] << 32) | $parts['low'];
        }

        throw new RuntimeException('Indefinite-length or reserved CBOR values are not supported.');
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
